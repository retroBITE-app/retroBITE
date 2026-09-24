<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MediaKind;
use App\Jobs\MakeThumbnails;
use App\Models\Media;
use Illuminate\Console\Command;

/**
 * Backfill the list and grid copies of covers downloaded before they existed.
 *
 * Not scheduled. Every cover downloaded from here on queues its own, so this
 * is a one-off for artwork that was already on the disk — and the way to make
 * them all again with --force after changing a size. It reads nothing from the
 * provider; the originals are already here.
 */
class MakeCoverThumbnails extends Command
{
    protected $signature = 'retrobite:thumbnails
                            {--force : Make them again for covers that already have them.}
                            {--queue : Queue them on the thumbnails queue instead of running them here.}';

    protected $description = 'Make the list and grid thumbnails for downloaded covers';

    public function handle(): int
    {
        $ids = Media::query()
            ->whereIn('screenscraper_type', MediaKind::Cover->screenScraperTypes())
            ->unless($this->option('force'), fn ($query) => $query->where(fn ($query) => $query
                ->whereNull('thumbnail_list_path')
                ->orWhereNull('thumbnail_grid_path')))
            ->pluck('id');

        if ($ids->isEmpty()) {
            $this->info('Every cover already has its thumbnails.');

            return self::SUCCESS;
        }

        $this->line("{$ids->count()} covers to make thumbnails for.");

        if ($this->option('queue')) {
            $ids->each(fn (int $id) => MakeThumbnails::dispatch($id));
            $this->info('Queued on the thumbnails queue.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($ids->count());

        foreach ($ids as $id) {
            (new MakeThumbnails($id))->handle();
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return self::SUCCESS;
    }
}
