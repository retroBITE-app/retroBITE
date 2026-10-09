<?php

use App\Enums\FileRole;
use App\Jobs\MatchGame;
use App\Jobs\RankLibrary;
use App\Jobs\RetroAchievements\SyncSet;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\LaunchBoxGame;
use App\Services\GameMatcher;
use App\Services\GameScorer;
use App\Services\LaunchBoxIndex;
use App\Services\LibraryRanking;
use App\Services\RetroAchievementsProgress;
use App\Services\RetroAchievementsService;
use App\Support\Console;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * The retroBite score's LaunchBox half: the index built from the public dump,
 * the lookup of a game in it, and the places a game is scored. The arithmetic
 * itself is tests/Unit/RetroBiteScoreTest.php.
 */

beforeEach(function () {
    $this->dump = sys_get_temp_dir().'/launchbox-'.Str::random(8);
    File::ensureDirectoryExists($this->dump);
});

afterEach(function () {
    File::deleteDirectory($this->dump);
});

/**
 * A Metadata.xml in the dump's own shape: games, then alternate names, with
 * the image and platform elements the importer has to step over.
 *
 * @param  list<array{0: int, 1: string, 2: string, 3?: float, 4?: int}>  $games  id, platform, name, rating, votes
 * @param  list<array{0: int, 1: string}>  $aliases  id, alternate name
 */
function launchBoxXml(array $games, array $aliases = []): string
{
    $xml = "<?xml version=\"1.0\" standalone=\"yes\"?>\n<LaunchBox>\n";
    $xml .= "  <Platform>\n    <Name>Super Nintendo Entertainment System</Name>\n  </Platform>\n";

    foreach ($games as $game) {
        [$id, $platform, $name] = $game;
        $rating = $game[3] ?? null;
        $votes = $game[4] ?? 0;

        $xml .= "  <Game>\n    <Name>".htmlspecialchars($name)."</Name>\n    <DatabaseID>{$id}</DatabaseID>\n";
        $xml .= $rating !== null ? "    <CommunityRating>{$rating}</CommunityRating>\n" : '';
        $xml .= '    <Platform>'.htmlspecialchars($platform)."</Platform>\n";
        $xml .= $votes > 0 ? "    <CommunityRatingCount>{$votes}</CommunityRatingCount>\n" : '';
        $xml .= "  </Game>\n";
    }

    foreach ($aliases as [$id, $name]) {
        $xml .= "  <GameAlternateName>\n    <AlternateName>".htmlspecialchars($name)."</AlternateName>\n    <DatabaseID>{$id}</DatabaseID>\n  </GameAlternateName>\n";
    }

    $xml .= "  <GameImage>\n    <DatabaseID>1</DatabaseID>\n    <FileName>x.png</FileName>\n  </GameImage>\n";

    return $xml."</LaunchBox>\n";
}

/** The importer's way in, without the download. */
function importLaunchBox(string $dir, string $xml): array
{
    file_put_contents($dir.'/Metadata.xml', $xml);

    return app(LaunchBoxIndex::class)->import($dir.'/Metadata.xml');
}

const LAUNCHBOX_SNES = 'Super Nintendo Entertainment System';

function launchBoxSnesDump(): string
{
    return launchBoxXml([
        [39777, LAUNCHBOX_SNES, 'Super Mario World', 4.73, 1836],
        [11, LAUNCHBOX_SNES, 'Shaq Fu', 1.98, 74],
        [12, LAUNCHBOX_SNES, 'The Legend of Zelda: A Link to the Past', 4.7, 1356],
        [13, LAUNCHBOX_SNES, 'Chrono Trigger', 4.8, 1000],
        // The second listing of one title on one platform, which nobody rates.
        [14, LAUNCHBOX_SNES, 'Chrono Trigger', 3.0, 2],
        [15, LAUNCHBOX_SNES, 'Nobody Voted', null, 0],
        [16, 'Arcade', 'Super Mario World', 3.9, 17],
        [17, 'Windows 3.X', 'Not A Console Anyone Maps', 4.0, 10],
    ], [
        [12, 'Zelda no Densetsu: Kamigami no Triforce'],
        [17, 'Ignored Alias'],
    ]);
}

function ratedSnesGame(string $title, array $attributes = []): Game
{
    return Game::factory()->forConsole('snes')->matched()->create(array_replace(['title' => $title, 'slug' => Str::slug($title)], $attributes));
}

it('keeps the games, names and averages of the platforms consoles map to', function () {
    $result = importLaunchBox($this->dump, launchBoxSnesDump());

    // Windows 3.X is mapped by no console, so neither it nor its alias is kept.
    expect($result['games'])->toBe(7)
        ->and(LaunchBoxGame::find(17))->toBeNull()
        ->and(DB::table('launchbox_names')->where('alias', true)->count())->toBe(1)
        ->and(LaunchBoxGame::find(39777)?->votes)->toBe(1836)
        ->and(LaunchBoxGame::find(15)?->rating)->toBeNull();

    $mean = (float) DB::table('launchbox_platforms')->where('name', LAUNCHBOX_SNES)->value('mean_rating');

    // Weighted by votes, so it is the average vote, not the average game.
    expect($mean)->toEqualWithDelta((4.73 * 1836 + 1.98 * 74 + 4.7 * 1356 + 4.8 * 1000 + 3.0 * 2) / (1836 + 74 + 1356 + 1000 + 2), 0.001);
});

it('replaces the whole index on every import', function () {
    importLaunchBox($this->dump, launchBoxSnesDump());
    importLaunchBox($this->dump, launchBoxXml([[39777, LAUNCHBOX_SNES, 'Super Mario World', 4.8, 2000]]));

    expect(LaunchBoxGame::count())->toBe(1)
        ->and(LaunchBoxGame::find(39777)?->votes)->toBe(2000);
});

it('keeps last week\'s index when the new one holds nothing it can use', function () {
    importLaunchBox($this->dump, launchBoxSnesDump());

    expect(fn () => importLaunchBox($this->dump, launchBoxXml([[1, 'Windows 3.X', 'Solitaire', 3.0, 5]])))
        ->toThrow(RuntimeException::class);

    expect(LaunchBoxGame::count())->toBe(7);
});

it('downloads the dump, reads it out of the zip and throws the zip away', function () {
    $zip = $this->dump.'/upload.zip';
    $archive = new ZipArchive;
    $archive->open($zip, ZipArchive::CREATE);
    $archive->addFromString('Metadata.xml', launchBoxSnesDump());
    $archive->close();

    Http::fake(['gamesdb.launchbox-app.com/*' => Http::response(file_get_contents($zip), 200)]);

    $result = app(LaunchBoxIndex::class)->sync();

    expect($result['games'])->toBe(7)
        ->and(File::exists(storage_path('app/launchbox/Metadata.zip')))->toBeFalse();
});

it('finds a game by its title however the library spells it', function () {
    importLaunchBox($this->dump, launchBoxSnesDump());
    $console = new Console('snes');
    $scorer = app(GameScorer::class);

    expect($scorer->find(ratedSnesGame('Legend Of Zelda, The - A Link To The Past'), $console)?->id)->toBe(12)
        ->and($scorer->find(ratedSnesGame('Zelda No Densetsu - Kamigami No Triforce'), $console)?->id)->toBe(12)
        // The listing people voted on, not the re-release beside it.
        ->and($scorer->find(ratedSnesGame('Chrono Trigger'), $console)?->id)->toBe(13)
        // The console's own platform before the arcade one.
        ->and($scorer->find(ratedSnesGame('Super Mario World'), $console)?->id)->toBe(39777)
        ->and($scorer->find(ratedSnesGame('Never Heard Of It'), $console))->toBeNull();
});

it('does not look up a game by its filename', function () {
    importLaunchBox($this->dump, launchBoxSnesDump());

    // A placeholder's title is its filename, and a hack's filename is the
    // game it hacks with a tag on the end.
    $hack = Game::factory()->forConsole('snes')->create(['title' => 'Super Mario World (Hack)', 'slug' => 'smw-hack']);

    expect(app(GameScorer::class)->find($hack, new Console('snes')))->toBeNull();
});

it('scores the library against the index, best above worst', function () {
    importLaunchBox($this->dump, launchBoxSnesDump());

    $best = ratedSnesGame('Super Mario World');
    $worst = ratedSnesGame('Shaq Fu');
    $unrated = ratedSnesGame('Nobody Voted');
    $unknown = ratedSnesGame('Never Heard Of It');

    expect(app(GameScorer::class)->scoreAll())->toBe(4);

    expect($best->refresh()->rating)->toBeGreaterThan(85)
        ->and($best->launchbox_id)->toBe(39777)
        ->and($worst->refresh()->rating)->toBeLessThan(35)
        // Found, but with no votes there is nothing to score it on.
        ->and($unrated->refresh()->rating)->toBeNull()
        ->and($unrated->launchbox_id)->toBe(15)
        ->and($unknown->refresh()->rating)->toBeNull()
        ->and($unknown->launchbox_id)->toBeNull();
});

it('scores a game the moment it is identified', function () {
    Bus::fake();
    importLaunchBox($this->dump, launchBoxSnesDump());

    Http::fake(['*' => Http::response(['response' => ['jeu' => [
        'id' => '4242',
        'noms' => [['region' => 'wor', 'text' => 'Super Mario World']],
        'note' => ['text' => '5'],
        'medias' => [],
    ]]], 200)]);

    $game = Game::factory()->forConsole('snes')->create(['title' => 'smw', 'slug' => 'smw']);
    GameFile::factory()->for($game)->create(['path' => 'snes/smw.sfc', 'filename' => 'smw.sfc', 'extension' => 'sfc', 'role' => FileRole::Rom]);

    (new MatchGame($game->id))->handle(app(GameMatcher::class));

    // LaunchBox's 4.73, not ScreenScraper's 5 out of 20.
    expect($game->refresh()->launchbox_id)->toBe(39777)
        ->and($game->rating)->toBeGreaterThan(85);
});

it('scores the games of a set when the set is synced', function () {
    config()->set('retroachievements.min_interval', 0);
    config()->set('retroachievements.api_key_fallback', 'test-key');

    $game = ratedSnesGame('Homebrew Nobody Rated', ['retroachievements_id' => 4111]);

    Http::fake(['*' => Http::response([
        'ID' => 4111, 'Title' => 'Homebrew', 'ConsoleID' => 3,
        'NumDistinctPlayers' => 50000, 'NumDistinctPlayersHardcore' => 400,
        'Achievements' => [
            1 => ['ID' => 1, 'Title' => 'Start', 'Points' => 5, 'NumAwarded' => 40000, 'NumAwardedHardcore' => 1, 'type' => 'progression'],
            2 => ['ID' => 2, 'Title' => 'Finish', 'Points' => 50, 'NumAwarded' => 10000, 'NumAwardedHardcore' => 1, 'type' => 'win_condition'],
        ],
    ], 200)]);

    (new SyncSet(4111))->handle(app(RetroAchievementsService::class), app(RetroAchievementsProgress::class));

    // RetroAchievements alone: above the middle, nowhere near the top.
    expect($game->refresh()->rating)->toBeGreaterThan(62)
        ->and($game->rating)->toBeLessThan(75);
});

it('rebuilds the index and scores the library from the command', function () {
    $zip = $this->dump.'/upload.zip';
    $archive = new ZipArchive;
    $archive->open($zip, ZipArchive::CREATE);
    $archive->addFromString('Metadata.xml', launchBoxSnesDump());
    $archive->close();

    Http::fake(['gamesdb.launchbox-app.com/*' => Http::response(file_get_contents($zip), 200)]);

    $game = ratedSnesGame('Super Mario World');

    $this->artisan('retrobite:launchbox:sync')
        ->expectsOutputToContain('Indexed 7 games')
        ->expectsOutputToContain('Scored 1 games')
        ->assertSuccessful();

    expect($game->refresh()->rating)->toBeGreaterThan(85);
});

/*
 * The retroBite rank: one order for the whole library, across consoles.
 */

it('ranks every scored game in the library, ties broken by the votes behind them', function () {
    LaunchBoxGame::query()->insert([
        ['id' => 1, 'platform' => LAUNCHBOX_SNES, 'name' => 'Many', 'rating' => 4.0, 'votes' => 900],
        ['id' => 2, 'platform' => LAUNCHBOX_SNES, 'name' => 'Few', 'rating' => 4.0, 'votes' => 30],
    ]);

    $best = ratedSnesGame('Best', ['rating' => 95]);
    $fewVotes = ratedSnesGame('Few', ['rating' => 80, 'launchbox_id' => 2]);
    $manyVotes = Game::factory()->forConsole('megadrive')->matched()->create(['title' => 'Many', 'slug' => 'many', 'rating' => 80, 'launchbox_id' => 1]);
    $worst = ratedSnesGame('Worst', ['rating' => 20]);
    $unscored = ratedSnesGame('Unscored');

    app(LibraryRanking::class)->rebuild();

    expect($best->refresh()->library_rank)->toBe(1)
        ->and($manyVotes->refresh()->library_rank)->toBe(2)
        ->and($fewVotes->refresh()->library_rank)->toBe(3)
        ->and($worst->refresh()->library_rank)->toBe(4)
        ->and($unscored->refresh()->library_rank)->toBeNull();

    // A game that loses its score loses its place, and the rest close up.
    $best->update(['rating' => null]);
    app(LibraryRanking::class)->rebuild();

    expect($best->refresh()->library_rank)->toBeNull()
        ->and($manyVotes->refresh()->library_rank)->toBe(1);
});

it('ranks the library after scoring all of it', function () {
    importLaunchBox($this->dump, launchBoxSnesDump());

    $zelda = ratedSnesGame('Legend Of Zelda, The - A Link To The Past');
    $smw = ratedSnesGame('Super Mario World');
    $shaq = ratedSnesGame('Shaq Fu');

    app(GameScorer::class)->scoreAll();

    expect($smw->refresh()->library_rank)->toBe(1)
        ->and($zelda->refresh()->library_rank)->toBe(2)
        ->and($shaq->refresh()->library_rank)->toBe(3);
});

it('queues the ranks to be worked out again when one score moves', function () {
    Queue::fake();
    importLaunchBox($this->dump, launchBoxSnesDump());

    app(GameScorer::class)->score(ratedSnesGame('Super Mario World'));

    Queue::assertPushed(RankLibrary::class, 1);
});

it('shows the rank with the score under the description', function () {
    $game = ratedSnesGame('A Link to the Past', ['rating' => 93, 'library_rank' => 5, 'description' => 'Hyrule, again.']);

    Livewire::test('games.show', ['game' => $game])
        ->assertSeeInOrder(['Hyrule, again.', '93', 'retroBite score', '#5'])
        ->assertDontSee('#5 of');
});

it('shows no score line for a game without one', function () {
    $game = ratedSnesGame('Obscure');

    Livewire::test('games.show', ['game' => $game])->assertDontSee('retroBite score');
});

it('shows the rank in the library table, but not on the cards', function () {
    ratedSnesGame('A Link to the Past', ['rating' => 93, 'library_rank' => 1720]);

    // A plain whole number, no separator: the column says what it is.
    Livewire::test('games.index')->set('view', 'table')->assertSeeHtml('>1720</td>');
    Livewire::test('games.index')->set('view', 'cards')->assertDontSee('1720');
});
