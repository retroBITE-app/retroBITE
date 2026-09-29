<?php

namespace App\Models;

use App\Enums\ConversionFailure;
use App\Enums\ConversionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * One conversion in the conversion queue, from the moment it is asked for.
 *
 * Run by RunConversion on the toolbox worker; see App\Conversion for what runs
 * it. The row outlives the job so the page can show how it went.
 *
 * @property int $id
 * @property string $console
 * @property string $converter a key in config/converters.php
 * @property int|null $game_id
 * @property int|null $game_file_id
 * @property string $label what the source list called it
 * @property string $directory where the output goes, inside the console's folder
 * @property list<string> $sources relative to the library root
 * @property list<string>|null $outputs file names, once written
 * @property array<string, mixed> $options
 * @property ConversionStatus $status
 * @property float $progress 0–100
 * @property int|null $source_bytes the discs that went in, once done
 * @property int|null $output_bytes what came out, once done
 * @property Carbon|null $eta_at when the running phase should end, going by its pace so far
 * @property Carbon|null $queued_at when it was last put in the queue: at first, and again on a retry
 * @property ConversionFailure|null $failure
 * @property string|null $log the tool's own output, the tail of it
 * @property Carbon|null $cancel_requested_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Game|null $game
 */
#[Fillable(['console', 'converter', 'game_id', 'game_file_id', 'label', 'directory', 'sources', 'outputs', 'options', 'status', 'progress', 'eta_at', 'source_bytes', 'output_bytes', 'failure', 'log', 'cancel_requested_at', 'queued_at', 'started_at', 'finished_at'])]
class Conversion extends Model
{
    protected $attributes = [
        'status' => 'queued',
        'progress' => 0,
    ];

    protected function casts(): array
    {
        return [
            'sources' => 'array',
            'outputs' => 'array',
            'options' => 'array',
            'status' => ConversionStatus::class,
            'progress' => 'float',
            'eta_at' => 'datetime',
            'source_bytes' => 'integer',
            'output_bytes' => 'integer',
            'failure' => ConversionFailure::class,
            'cancel_requested_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** Whether an option was ticked, for the boolean ones. */
    public function option(string $key): bool
    {
        return (bool) Arr::get($this->options, $key, false);
    }

    /** The folder inside upload staging this conversion writes into, and nothing else does. */
    public function stagingFolder(): string
    {
        return 'conv-'.$this->id;
    }

    /**
     * The ones still waiting or running.
     *
     * @param  Builder<Conversion>  $query
     */
    public function scopeUnfinished(Builder $query): void
    {
        $query->whereNotIn('status', ConversionStatus::finishedCases());
    }

    /**
     * The ones that are over: done, failed or cancelled.
     *
     * @param  Builder<Conversion>  $query
     */
    public function scopeFinished(Builder $query): void
    {
        $query->whereIn('status', ConversionStatus::finishedCases());
    }

    /**
     * The ones a worker holds right now.
     *
     * @param  Builder<Conversion>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', ConversionStatus::activeCases());
    }
}
