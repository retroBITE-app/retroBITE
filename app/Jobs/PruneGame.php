<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AppSetting;
use App\Models\Conversion;
use App\Models\Game;
use App\Models\GameFile;
use App\Services\MediaLibrary;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Prune one game: forget its ROMs gone longer than the Library setting allows, and the game once it has none.
 *
 * Queued per game by `retrobite:library:prune`, which finds the two kinds
 * worth asking about: games with no file rows at all, and games with a file
 * marked missing. A scan marks a file missing the first time it fails to find
 * it and never deletes the row, so this is where a ROM moved away stops being
 * listed:
 *
 * 1. Each of the game's file rows missing for longer than the cutoff is
 *    deleted. Other files, and ones gone only lately, stay.
 * 2. If that leaves the game with no file rows — or it never had any — the
 *    game goes, with its details and artwork.
 *
 * A game a conversion still queued or running points at is left alone until
 * that conversion is over. Safe to run twice: a game already gone, or with
 * nothing past the cutoff, is a no-op.
 */
class PruneGame implements ShouldQueue
{
    use Queueable;

    /** One game's rows and artwork; far under the default connection's retry_after. */
    public int $timeout = 120;

    public int $tries = 1;

    /**
     * @param  int  $days  how long a file must have been missing before its row goes
     */
    public function __construct(public readonly int $gameId, public readonly int $days)
    {
        $this->onQueue('default');
    }

    /** The days Settings → Library has saved, at least one. */
    public static function savedDays(): int
    {
        return max(1, (int) AppSetting::get(AppSetting::PRUNE_MISSING_AFTER_DAYS));
    }

    /** The moment a file must have gone missing before, for a prune of this many days. */
    public static function cutoff(int $days): CarbonInterface
    {
        return now()->subDays($days);
    }

    /**
     * Games with no file rows at all, and no conversion waiting on them.
     *
     * @return Builder<Game>
     */
    public static function withoutFiles(): Builder
    {
        return self::notConverting(Game::query()->whereDoesntHave('files'));
    }

    /**
     * Games with at least one file marked missing, and no conversion waiting
     * on them. Whether any has been gone long enough is the job's to decide.
     *
     * @return Builder<Game>
     */
    public static function withMissingFiles(): Builder
    {
        return self::notConverting(Game::query()->whereHas('files', function (Builder $query): void {
            /** @var Builder<GameFile> $query */
            $query->whereNotNull('missing_since');
        }));
    }

    /**
     * The games a prune with this cutoff would end up deleting, for Settings →
     * Library to count: none of their files still there or gone only lately.
     *
     * @return Builder<Game>
     */
    public static function wouldRemove(CarbonInterface $cutoff): Builder
    {
        return self::notConverting(Game::query()->whereDoesntHave('files', function (Builder $query) use ($cutoff): void {
            /** @var Builder<GameFile> $query */
            $query->whereNull('missing_since')->orWhere('missing_since', '>', $cutoff);
        }));
    }

    /**
     * File rows a prune with this cutoff would delete, for the same count.
     *
     * @return Builder<GameFile>
     */
    public static function staleFiles(CarbonInterface $cutoff): Builder
    {
        return GameFile::query()
            ->where('missing_since', '<=', $cutoff)
            ->whereNotIn('game_id', Conversion::query()->unfinished()->whereNotNull('game_id')->select('game_id'));
    }

    public function handle(MediaLibrary $media): void
    {
        $game = Game::query()->find($this->gameId);

        // Gone already, or a conversion queued since this was.
        if ($game === null || Conversion::query()->unfinished()->where('game_id', $game->id)->exists()) {
            return;
        }

        $files = $game->files()->where('missing_since', '<=', self::cutoff($this->days))->delete();

        if ($game->files()->exists()) {
            $this->record($game, $files, false);

            return;
        }

        $media->forgetGames([$game->id]);
        $game->delete();

        $this->record($game, $files, true);
    }

    /**
     * @param  Builder<Game>  $query
     * @return Builder<Game>
     */
    private static function notConverting(Builder $query): Builder
    {
        return $query->whereNotIn('id', Conversion::query()->unfinished()->whereNotNull('game_id')->select('game_id'));
    }

    /** What went, in the log and, when anything did, the activity log. */
    private function record(Game $game, int $files, bool $removed): void
    {
        if ($files === 0 && ! $removed) {
            return;
        }

        $properties = [
            'game' => $game->console.': '.$game->title,
            'days' => $this->days,
            'files' => $files,
            'removed' => $removed,
        ];

        Log::info($removed ? 'Pruned a game left without ROMs.' : 'Forgot missing ROMs of a game.', $properties);
        activity('library')->withProperties($properties)->log($removed ? 'pruned' : 'forgot missing files');
    }
}
