<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AchievementKind;
use App\Enums\GameStatus;
use App\Events\GameUpdated;
use App\Jobs\RankLibrary;
use App\Models\Game;
use App\Models\LaunchBoxGame;
use App\Models\RaAchievement;
use App\Models\RaGame;
use App\Support\Console;
use App\Support\LaunchBox\LaunchBoxTitle;
use App\Support\LiveUpdates;
use App\Support\RetroBiteScore;
use Illuminate\Support\Facades\DB;

/**
 * Works out a game's retroBite score and writes it to games.rating.
 *
 * Everything it reads is already in the database — the LaunchBox index and
 * the achievement sets — so scoring costs no request and is done whenever an
 * ingredient changes: after a match, after a set sync, after the index is
 * rebuilt. The arithmetic is RetroBiteScore's; this gathers its inputs.
 */
final class GameScorer
{
    /**
     * Engagement per set, for the length of one scoreAll(): a set is shared
     * by every region of a title.
     *
     * @var array<int, float|null>
     */
    private array $engagement = [];

    /** @var array<string, float|null> */
    private array $platformMeans = [];

    public function __construct(private readonly LibraryRanking $ranking) {}

    /**
     * Score one game, write it when it changed, and say so when $announce.
     *
     * Also refreshes which LaunchBox entry the game is, because the title or
     * the index may have moved since it was last scored. A changed score
     * moves the library's ranks, which are queued to be worked out again
     * unless $rank is off — scoreAll() works them out once at the end.
     */
    public function score(Game $game, bool $announce = true, bool $rank = true): ?int
    {
        $console = $game->console();
        $entry = $console !== null ? $this->find($game, $console) : null;
        $set = $game->retroachievements_id !== null
            ? ($game->relationLoaded('raGame') ? $game->raGame : RaGame::find($game->retroachievements_id))
            : null;

        // A set the index lists but nobody has synced has no player count
        // yet; zero would read as a game nobody plays.
        $players = $set?->set_synced_at !== null ? $set->num_distinct_players : null;

        $score = RetroBiteScore::compute(
            rating: $entry?->rating,
            votes: $entry->votes ?? 0,
            platformMean: $this->platformMean($entry->platform ?? $console?->launchboxPlatforms[0] ?? null),
            players: $players,
            engagement: $set !== null && $players ? $this->engagement($set->id, $players) : null,
        );

        // Compared by value rather than with isDirty(): a model just created
        // has no launchbox_id among its originals at all, and would read as
        // changed every time.
        if ($game->rating !== $score || $game->launchbox_id !== $entry?->id) {
            $game->update(['rating' => $score, 'launchbox_id' => $entry?->id]);

            if ($rank) {
                RankLibrary::dispatch();
            }

            if ($announce) {
                LiveUpdates::game($game->id, GameUpdated::RATING);
            }
        }

        return $score;
    }

    /**
     * Score every game, or every game on one console, without a live signal
     * per row: a rebuilt index changes thousands at once. The ranks are
     * worked out once at the end, here rather than queued.
     *
     * @return int how many games were scored
     */
    public function scoreAll(?string $console = null): int
    {
        $this->engagement = [];
        $this->platformMeans = [];
        $count = 0;

        Game::query()
            ->with('raGame')
            ->when($console !== null, fn ($query) => $query->forConsole((string) $console))
            ->chunkById(500, function ($games) use (&$count) {
                foreach ($games as $game) {
                    $this->score($game, announce: false, rank: false);
                    $count++;
                }
            });

        $this->ranking->rebuild();

        return $count;
    }

    /**
     * Score the games that are one achievement set, after it was synced.
     *
     * @return int how many games were scored
     */
    public function scoreSet(int $raGameId): int
    {
        unset($this->engagement[$raGameId]);

        $games = Game::query()->where('retroachievements_id', $raGameId)->get();

        foreach ($games as $game) {
            $this->score($game);
        }

        return $games->count();
    }

    /**
     * The LaunchBox entry for a game, or null.
     *
     * Only for an identified game: a placeholder's title is its filename,
     * and "Super Mario World (Hack)" with its tag stripped would take Super
     * Mario World's rating. Primary names before alternate ones, the
     * console's likeliest platform first, then the entry most people voted
     * on — LaunchBox lists some titles twice on one platform, and the
     * second is a re-release nobody rates.
     */
    public function find(Game $game, Console $console): ?LaunchBoxGame
    {
        if ($game->status !== GameStatus::Matched || $console->launchboxPlatforms === []) {
            return null;
        }

        $key = LaunchBoxTitle::key($game->title);

        if ($key === '') {
            return null;
        }

        $platforms = $console->launchboxPlatforms;

        return LaunchBoxGame::query()
            ->select('launchbox_games.*')
            ->join('launchbox_names', 'launchbox_names.launchbox_game_id', '=', 'launchbox_games.id')
            ->whereIn('launchbox_names.platform', $platforms)
            ->where('launchbox_names.name_key', $key)
            ->orderBy('launchbox_names.alias')
            ->orderByRaw('FIELD(launchbox_names.platform, '.implode(', ', array_fill(0, count($platforms), '?')).')', $platforms)
            ->orderByDesc('launchbox_games.votes')
            ->orderBy('launchbox_games.id')
            ->first();
    }

    private function platformMean(?string $platform): ?float
    {
        if ($platform === null) {
            return null;
        }

        if (! array_key_exists($platform, $this->platformMeans)) {
            $mean = DB::table('launchbox_platforms')->where('name', $platform)->value('mean_rating');
            $this->platformMeans[$platform] = $mean !== null ? (float) $mean : null;
        }

        return $this->platformMeans[$platform];
    }

    /**
     * How far the typical player of a set got: the median unlock count of
     * its progression and win achievements, as a share of everyone who
     * played it. A set without those marked falls back on all of its
     * achievements, whose median is a fair stand-in.
     */
    private function engagement(int $raGameId, int $players): ?float
    {
        if (! array_key_exists($raGameId, $this->engagement)) {
            $achievements = RaAchievement::query()->counting()->where('ra_game_id', $raGameId);

            $steps = (clone $achievements)
                ->whereIn('kind', [AchievementKind::Progression->value, AchievementKind::WinCondition->value])
                ->pluck('num_awarded');

            if ($steps->isEmpty()) {
                $steps = $achievements->pluck('num_awarded');
            }

            $this->engagement[$raGameId] = $steps->isEmpty()
                ? null
                : (float) $steps->map(fn ($awarded) => (int) $awarded)->median() / $players;
        }

        return $this->engagement[$raGameId];
    }
}
