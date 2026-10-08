<?php

declare(strict_types=1);

namespace App\Conversion;

use App\Enums\ConversionFailure;
use App\Enums\ConversionStatus;
use App\Events\SystemUpdated;
use App\Exceptions\ConversionFailed;
use App\Jobs\RunConversion;
use App\Models\Conversion;
use App\Support\LiveUpdates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

/**
 * What the Conversion page does to the queue: add a conversion, cancel one,
 * retry one, clear away the finished.
 *
 * Adding goes through the same gate the job asks again, so the page cannot
 * queue what it was never offered however the request is put together.
 */
final class ConversionQueue
{
    /**
     * Queue one set through one converter, and start it.
     *
     * @param  array<string, mixed>  $options  only the ones the converter declares are kept
     *
     * @throws ConversionFailed
     */
    public function add(SourceSet $set, string $converterKey, array $options): Conversion
    {
        $conversion = $this->queue($set, $converterKey, $options);

        $this->signal();

        return $conversion;
    }

    /**
     * Queue many sets through one converter, one conversion each, with one
     * live signal for the lot rather than a broadcast per set. The sets the
     * converter does not take are counted, not thrown.
     *
     * @param  iterable<SourceSet>  $sets
     * @param  array<string, mixed>  $options
     * @return array{queued: list<Conversion>, skipped: int}
     */
    public function addMany(iterable $sets, string $converterKey, array $options): array
    {
        $queued = [];
        $skipped = 0;

        foreach ($sets as $set) {
            try {
                $queued[] = $this->queue($set, $converterKey, $options);
            } catch (ConversionFailed) {
                $skipped++;
            }
        }

        if ($queued !== []) {
            $this->signal();
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Stop one. Waiting, it is cancelled here and now; running, the worker
     * is asked to stop it and takes it from there. Decided by the row as it
     * is at that moment, not as the page last saw it, so a conversion the
     * worker picked up meanwhile is asked to stop rather than overwritten.
     */
    public function cancel(Conversion $conversion): void
    {
        if (! $conversion->status->cancellable()) {
            return;
        }

        $cancelled = Conversion::query()
            ->whereKey($conversion->id)
            ->where('status', ConversionStatus::Queued)
            ->update(['status' => ConversionStatus::Cancelled, 'finished_at' => now()]);

        if ($cancelled === 0) {
            $conversion->update(['cancel_requested_at' => now()]);
        }

        $this->signal();
    }

    /** Put a failed or cancelled one back in the queue as it was asked for. */
    public function retry(Conversion $conversion): void
    {
        if (! $conversion->status->retryable()) {
            return;
        }

        $conversion->update([
            'status' => ConversionStatus::Queued,
            'queued_at' => now(),
            'progress' => 0,
            'eta_at' => null,
            'source_bytes' => null,
            'output_bytes' => null,
            'failure' => null,
            'log' => null,
            'outputs' => null,
            'cancel_requested_at' => null,
            'started_at' => null,
            'finished_at' => null,
        ]);

        RunConversion::dispatch($conversion->id);

        $this->signal();
    }

    /** Take one finished row off the list. */
    public function remove(Conversion $conversion): void
    {
        if (! $conversion->status->finished()) {
            return;
        }

        $conversion->delete();

        $this->signal();
    }

    /** Take every finished row off the list, or one converter's. Returns how many went. */
    public function clearFinished(?string $converter = null): int
    {
        $cleared = Conversion::query()
            ->finished()
            ->when($converter !== null, function (Builder $query) use ($converter): void {
                $query->where('converter', $converter);
            })
            ->delete();

        $this->signal();

        return (int) $cleared;
    }

    /**
     * Make the row and dispatch its job, once the gate has let it through.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ConversionFailed
     */
    private function queue(SourceSet $set, string $converterKey, array $options): Conversion
    {
        $converter = Converters::routesFor($set)->first(function (Converter $converter) use ($converterKey): bool {
            return $converter->key() === $converterKey;
        });

        if (! $converter instanceof Converter) {
            throw ConversionFailed::because(ConversionFailure::Unsupported, $converterKey);
        }

        $conversion = Conversion::query()->create([
            'console' => $set->console->key,
            'converter' => $converter->key(),
            'game_id' => $set->game?->id,
            'game_file_id' => $set->file->id,
            'label' => $set->label(),
            'directory' => $set->directory,
            'sources' => $set->paths(),
            'options' => $this->options($converter, $options),
            'status' => ConversionStatus::Queued,
            'queued_at' => now(),
        ]);

        RunConversion::dispatch($conversion->id);

        return $conversion;
    }

    /**
     * The options the converter understands, from what the page sent, with
     * keep-source on unless it was turned off, the compression one it offers,
     * and every advanced setting resolved to one of its own choices.
     *
     * @param  array<string, mixed>  $sent
     * @return array<string, mixed>
     */
    private function options(Converter $converter, array $sent): array
    {
        $options = collect($converter->options())
            ->mapWithKeys(function (string $option) use ($converter, $sent): array {
                return [$option => match ($option) {
                    Converter::COMPRESSION => $converter->compression($sent),
                    Converter::KEEP_SOURCE => (bool) Arr::get($sent, $option, true),
                    default => (bool) Arr::get($sent, $option, false),
                }];
            })
            ->all();

        if ($converter->settings() !== []) {
            $options[Converter::ADVANCED] = collect($converter->settings())
                ->mapWithKeys(function (Setting $setting) use ($sent): array {
                    return [$setting->key => $setting->resolve(Arr::get($sent, Converter::ADVANCED.'.'.$setting->key))];
                })
                ->all();
        }

        return $options;
    }

    private function signal(): void
    {
        LiveUpdates::system(SystemUpdated::CONVERSION);
    }
}
