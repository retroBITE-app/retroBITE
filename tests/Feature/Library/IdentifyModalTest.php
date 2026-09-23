<?php

use App\Enums\FileRole;
use App\Enums\GameStatus;
use App\Jobs\HashFile;
use App\Jobs\ScrapeGameMedia;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Queue::fake();
});

/** A game with one data track, hashed unless told otherwise. */
function unidentifiedGame(?string $md5 = 'd41d8cd98f00b204e9800998ecf8427e'): Game
{
    $game = Game::factory()->forConsole('psx')->unmatched()->create(['title' => 'FF9 Disc 1', 'slug' => 'ff9-disc-1']);

    GameFile::factory()->for($game)->create([
        'path' => 'psx/FF9 Disc 1.bin',
        'filename' => 'FF9 Disc 1.bin',
        'extension' => 'bin',
        'role' => FileRole::Track,
        'md5' => $md5,
    ]);

    return $game;
}

/** One provider game, in the shape both endpoints answer with. */
function providerGame(string $id, string $title): array
{
    return [
        'id' => $id,
        'noms' => [['region' => 'ss', 'text' => $title]],
        'dates' => [['region' => 'ss', 'text' => '2000-02-07']],
        'regions' => ['regions_shortname' => ['eu']],
        'rom' => ['romfilename' => $title.'.bin'],
        'medias' => [[
            'type' => 'box-2D',
            'region' => 'eu',
            'format' => 'png',
            'url' => 'https://neoclone.screenscraper.fr/api2/mediaJeu.php?devid=me&devpassword=hunter2&ssid=tomas&sspassword=topsecret&jeuid='.$id.'&media=box-2D(eu)',
        ]],
    ];
}

/**
 * A search answering with these games, and jeuInfos answering with $info (null for a miss).
 *
 * @param  array<int, array<string, mixed>>  $found
 * @param  array<string, mixed>|null  $info
 */
function fakeProvider(array $found, ?array $info = null): void
{
    $ssuser = ['maxthreads' => '1', 'requeststoday' => '1', 'maxrequestsperday' => '20000'];

    Http::fake([
        '*jeuRecherche.php*' => Http::response(['response' => ['ssuser' => $ssuser, 'jeux' => $found]]),
        '*jeuInfos.php*' => $info === null
            ? Http::response(mb_convert_encoding('Erreur : Jeu non trouvée !', 'ISO-8859-1', 'UTF-8'), 404)
            : Http::response(['response' => ['ssuser' => $ssuser, 'jeu' => $info]]),
    ]);
}

it('lists name matches without letting a provider credential reach the browser', function () {
    $game = unidentifiedGame();
    fakeProvider([providerGame('19256', 'Final Fantasy IX'), providerGame('19257', 'Final Fantasy VIII')]);

    $component = Livewire::test('games.identify-modal', ['gameId' => $game->id])
        ->call('open')
        ->assertSet('search', 'FF9 Disc 1')
        ->assertSet('searched', true)
        ->assertSee('Final Fantasy IX')
        ->assertSee('Final Fantasy VIII')
        ->assertDontSee('sspassword')
        ->assertDontSee('hunter2');

    expect(json_encode($component->get('candidates')))->not->toContain('topsecret');
});

it('offers the exact checksum match when the file is hashed', function () {
    $game = unidentifiedGame();
    fakeProvider([], providerGame('19256', 'Final Fantasy IX'));

    $component = Livewire::test('games.identify-modal', ['gameId' => $game->id])
        ->call('open')
        ->assertSee('Exact MD5 match')
        ->assertSee('Final Fantasy IX');

    expect($component->get('md5Match'))->not->toHaveKey('raw')->not->toHaveKey('cover_url');

    Queue::assertNotPushed(HashFile::class);
});

it('keeps the checksum match through a search typed by hand', function () {
    $game = unidentifiedGame();
    fakeProvider([providerGame('19257', 'Final Fantasy VIII')], providerGame('19256', 'Final Fantasy IX'));

    $component = Livewire::test('games.identify-modal', ['gameId' => $game->id])->call('open');

    Http::assertSentCount(2);

    $component->set('search', 'Final Fantasy')->call('lookup');

    // Only the search is asked again; the checksum answer is about the file, not the words.
    Http::assertSentCount(3);
    $component->assertNotSet('md5Match', null);
});

it('hashes an unhashed file once, then asks by checksum when the hash lands', function () {
    $game = unidentifiedGame(md5: null);
    fakeProvider([], providerGame('19256', 'Final Fantasy IX'));

    $component = Livewire::test('games.identify-modal', ['gameId' => $game->id])
        ->call('open')
        ->assertSee('Hashing file for an exact match')
        ->assertSet('md5Match', null);

    Queue::assertPushed(HashFile::class, 1);

    // Reopening while the first read is still under way queues nothing more.
    $component->call('closed')->call('open');
    Queue::assertPushed(HashFile::class, 1);

    $component->call('checkHash')->assertNotSet('hashingFileId', null);

    $game->files()->update(['md5' => 'd41d8cd98f00b204e9800998ecf8427e']);

    $component->call('checkHash')
        ->assertSet('hashingFileId', null)
        ->assertSee('Exact MD5 match');
});

it('stops waiting on a hash that never arrives', function () {
    $game = unidentifiedGame(md5: null);
    fakeProvider([]);

    $component = Livewire::test('games.identify-modal', ['gameId' => $game->id])->call('open');

    $this->travel(61)->minutes();

    $component->call('checkHash')->assertSet('hashingFileId', null);
});

it('applies a picked match and hands back to the page', function () {
    $game = unidentifiedGame();
    fakeProvider([providerGame('19256', 'Final Fantasy IX')], providerGame('19256', 'Final Fantasy IX'));

    Livewire::test('games.identify-modal', ['gameId' => $game->id])
        ->call('open')
        ->call('assign', 19256)
        ->assertDispatched('game-identified')
        ->assertDispatched('toast-show');

    expect($game->refresh())
        ->status->toBe(GameStatus::Matched)
        ->screenscraper_id->toBe(19256)
        ->title->toBe('Final Fantasy IX');

    Queue::assertPushed(ScrapeGameMedia::class);
});

it('refuses an id the modal never listed', function () {
    // Unhashed, so jeuInfos is never asked by checksum and 666 is listed nowhere.
    $game = unidentifiedGame(md5: null);
    fakeProvider([providerGame('19256', 'Final Fantasy IX')], providerGame('666', 'Something Else'));

    Livewire::test('games.identify-modal', ['gameId' => $game->id])
        ->call('open')
        ->call('assign', 666)
        ->assertNotDispatched('game-identified');

    expect($game->refresh()->status)->toBe(GameStatus::Unmatched);
    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->url(), 'gameid=666');
    });
});

it('moves to the surviving game when the pick is one already in the library', function () {
    $existing = Game::factory()->forConsole('psx')->matched(19256)->create(['title' => 'Final Fantasy IX', 'slug' => 'final-fantasy-ix']);
    $game = unidentifiedGame();
    fakeProvider([providerGame('19256', 'Final Fantasy IX')], providerGame('19256', 'Final Fantasy IX'));

    Livewire::test('games.identify-modal', ['gameId' => $game->id])
        ->call('open')
        ->call('assign', 19256)
        ->assertRedirect(route('games.show', $existing->routeParameters()));

    expect(Game::find($game->id))->toBeNull()
        ->and($existing->files()->count())->toBe(1);
});

it('shows a fixed message when the provider cannot be reached', function () {
    $game = unidentifiedGame();
    Http::fake(['*' => Http::response('', 503)]);

    Livewire::test('games.identify-modal', ['gameId' => $game->id])
        ->call('open')
        ->assertDispatched('toast-show')
        ->assertSet('candidates', []);
});

it('offers a hand-picked match on a matched game, and says why not on an unmapped console', function () {
    $game = Game::factory()->forConsole('psx')->matched(19256)->create(['title' => 'Final Fantasy IX', 'slug' => 'final-fantasy-ix']);

    $this->get(route('games.show', $game->routeParameters()))->assertOk()->assertSee('Identify manually');

    config()->set('consoles.psx.screenscraper_id', null);

    $this->get(route('games.show', $game->routeParameters()))
        ->assertOk()
        ->assertSee('This console is not mapped to ScreenScraper.');
});

it('reloads the page once the modal reports a match', function () {
    $game = unidentifiedGame();

    $component = Livewire::test('games.show', ['game' => $game]);

    $game->update(['title' => 'Final Fantasy IX', 'status' => GameStatus::Matched, 'screenscraper_id' => 19256]);

    $component->dispatch('game-identified')
        ->assertSee('Final Fantasy IX')
        ->assertNotSet('fetchingFrom', null);
});
