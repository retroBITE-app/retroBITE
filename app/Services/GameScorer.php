<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AchievementKind;
use App\Enums\GameStatus;
use App\Events\GameUpdated;
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
 * Everything it reads is already in the database — the LaunchBox index, the
 * achievement sets and the provider's scrape counts — so scoring costs no request and is done whenever an
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

    /** Whether the LaunchBox index holds anything, once asked. */
    private ?bool $indexed = null;

    public function __construct(private readonly LibraryRanking $ranking) {}

    /**
     * Score one game, write it when it changed, and say so when $announce.
     *
     * Also refreshes which LaunchBox entry the game is, because the title or
     * the index may have moved since it was last scored. A changed score
     * moves the library's ranks, which are marked stale for the scheduler
     * to work out again unless $rank is off — scoreAll() works them out once
     * at the end.
     */
    public function score(Game $game, bool $announce = true, bool $rank = true): ?int
    {
        // No index yet — a new install before its first sync, or one emptied
        // to be rebuilt. Scored now, every game would lose its LaunchBox
        // half; the sync scores the whole library once it is in.
        $this->indexed ??= DB::table('launchbox_platforms')->exists();

        if (! $this->indexed) {
            return $game->rating;
        }

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
            votePercentile: $entry !== null ? $this->votePercentile($entry->platform, $entry->votes) : null,
            scrapes: $game->provider_scrapes,
        );

        // Compared by value rather than with isDirty(): a model just created
        // has no launchbox_id among its originals at all, and would read as
        // changed every time.
        if ($game->rating !== $score || $game->launchbox_id !== $entry?->id) {
            $game->update(['rating' => $score, 'launchbox_id' => $entry?->id]);

            if ($rank) {
                $this->ranking->markStale();
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
        $this->indexed = null;
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
     * Score these games, without a live signal per row and without working
     * out the ranks: one chunk of a queued `retrobite:score`, whose batch
     * ranks once at the end.
     *
     * @param  list<int>  $ids
     * @return int how many games were scored
     */
    public function scoreMany(array $ids): int
    {
        $games = Game::query()->with('raGame')->whereIn('id', $ids)->get();

        foreach ($games as $game) {
            $this->score($game, announce: false, rank: false);
        }

        return $games->count();
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
     * Mario World's rating. Whole names before ones with the subtitle
     * left off, primary names before alternate ones, the
     * console's likeliest platform first, then the entry most people voted
     * on — LaunchBox lists some titles twice on one platform, and the
     * second is a re-release nobody rates. Each of the game's names in turn
     * (namesOf), the first that is found winning.
     */
    public function find(Game $game, Console $console): ?LaunchBoxGame
    {
        if ($game->status !== GameStatus::Matched || $console->launchboxPlatforms === []) {
            return null;
        }

        foreach ($this->namesOf($game) as $name) {
            $entry = $this->findByName($name, $console->launchboxPlatforms);

            if ($entry !== null) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * What a game is called, best first: its own title, then the title of
     * its RetroAchievements set, which is English and spelled as the
     * community spells it — "Fire Emblem: The Binding Blade" where
     * ScreenScraper has "Fire Emblem - Fûin No Tsurugi".
     *
     * Never a set marked as a hack, prototype, demo or subset: those carry
     * the name of the game they are not, and would take its rating. An
     * unlicensed or homebrew game is itself, so only its mark goes.
     *
     * @return list<string>
     */
    private function namesOf(Game $game): array
    {
        $names = [$game->title];
        $set = $game->retroachievements_id !== null
            ? ($game->relationLoaded('raGame') ? $game->raGame : RaGame::find($game->retroachievements_id))
            : null;
        $title = $set?->title;

        if ($title !== null && $title !== '' && ! str_contains($title, '[Subset')
            && preg_match('/~(?:Hack|Prototype|Demo|Test Kit)~/i', $title) !== 1) {
            $names[] = trim((string) preg_replace('/~[^~]*~/', '', $title));
        }

        return array_values(array_unique($names));
    }

    /** @param  list<string>  $platforms */
    private function findByName(string $name, array $platforms): ?LaunchBoxGame
    {
        $key = LaunchBoxTitle::key($name);

        if ($key === '') {
            return null;
        }

        return LaunchBoxGame::query()
            ->select('launchbox_games.*')
            ->join('launchbox_names', 'launchbox_names.launchbox_game_id', '=', 'launchbox_games.id')
            ->whereIn('launchbox_names.platform', $platforms)
            ->where('launchbox_names.name_key', $key)
            ->orderBy('launchbox_names.shortened')
            ->orderBy('launchbox_names.alias')
            ->orderByRaw('FIELD(launchbox_names.platform, '.implode(', ', array_fill(0, count($platforms), '?')).')', $platforms)
            ->orderByDesc('launchbox_games.votes')
            ->orderBy('launchbox_games.id')
            ->first();
    }

    /**
     * Where a vote count stands among every game LaunchBox lists on the
     * platform, 0 to 100: against the platform, because 700 votes is a
     * classic on the PlayStation and the whole Lynx library's worth. Ties
     * count half, so a platform's many unrated games sit low, not at zero.
     *
     * Counted in the database, one query a game: a scorer lives for one job,
     * and a platform's every vote count read into each would be read again
     * for every match of an identification run.
     */
    private function votePercentile(string $platform, int $votes): ?float
    {
        $counts = DB::table('launchbox_games')
            ->where('platform', $platform)
            ->selectRaw('COUNT(*) AS total, SUM(votes < ?) AS below, SUM(votes = ?) AS equal', [$votes, $votes])
            ->first();

        $total = (int) ($counts->total ?? 0);

        if ($total === 0) {
            return null;
        }

        return ((int) $counts->below + (int) $counts->equal / 2) / $total * 100;
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
