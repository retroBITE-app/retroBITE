<?php

use App\Enums\FileRole;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\Media;
use App\Models\User;
use App\Support\Console;
use App\Transfers\BatoceraTarget;
use App\Transfers\ConsoleTransfer;
use App\Transfers\DaijishouTarget;
use App\Transfers\EsDeTarget;
use App\Transfers\RecalboxTarget;
use App\Transfers\RetroPieTarget;
use App\Transfers\TransferTarget;
use App\Transfers\TransferTargets;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * The layouts other than Batocera's, which BatoceraTest pins down: each
 * front-end's folders, file names, system names and game list, for the same
 * games.
 */

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-targets-'.Str::random(8);
    File::ensureDirectoryExists($this->root);
    config()->set('settings.games_path', $this->root);

    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function targetFile(Game $game, string $path, FileRole $role, ?int $disc = null): GameFile
{
    File::ensureDirectoryExists(dirname(test()->root.'/'.$path));
    File::put(test()->root.'/'.$path, 'rom-bytes');

    return GameFile::factory()->for($game)->create([
        'path' => $path,
        'filename' => basename($path),
        'extension' => pathinfo($path, PATHINFO_EXTENSION),
        'role' => $role,
        'disc_number' => $disc,
        'size_bytes' => 9,
    ]);
}

/** Super Metroid with a box, a screenshot, a title screen, a logo, a mix and a video. */
function superMetroid(): Game
{
    ConsoleSourceFolder::add(new Console('snes'));
    $game = Game::factory()->forConsole('snes')->matched()->create([
        'title' => 'Super Metroid', 'slug' => 'super-metroid', 'description' => 'Samus returns.',
        'genre' => 'Action, Platform', 'rating' => 90, 'release_date' => '1994-03-19',
    ]);
    targetFile($game, 'snes/Super Metroid (USA).sfc', FileRole::Rom);

    foreach (['box-2D' => 'png', 'ss' => 'png', 'sstitle' => 'png', 'wheel' => 'png', 'mixrbv2' => 'png', 'video-normalized' => 'mp4'] as $type => $extension) {
        Media::factory()->for($game)->ofType($type, 'us')->create(['path' => "snes/sm/{$type}/x.{$extension}", 'extension' => $extension, 'size_bytes' => 10]);
    }

    return $game->fresh();
}

/** Final Fantasy IX: a playlist in a folder of its own, two discs. */
function finalFantasyIx(): Game
{
    ConsoleSourceFolder::add(new Console('psx'));
    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Final Fantasy IX', 'slug' => 'ff9']);
    $playlist = targetFile($game, 'psx/Final Fantasy IX/Final Fantasy IX.m3u', FileRole::Playlist);
    targetFile($game, 'psx/Final Fantasy IX/Disc 1.chd', FileRole::Rom, 1)->update(['parent_id' => $playlist->id]);
    targetFile($game, 'psx/Final Fantasy IX/Disc 2.chd', FileRole::Rom, 2)->update(['parent_id' => $playlist->id]);
    Media::factory()->for($game)->ofType('box-2D', 'us')->create(['path' => 'psx/ff9/box-2D/x.png', 'extension' => 'png', 'size_bytes' => 10]);

    return $game->fresh();
}

/** @return list<string> */
function destinations(TransferTarget $target, Game $game): array
{
    return array_column($target->plan($game)->toArray()['files'], 'destination');
}

it('offers every front-end, Batocera first', function () {
    expect(array_map(fn (TransferTarget $target) => $target->key(), TransferTargets::all()))
        ->toBe(['batocera', 'recalbox', 'retropie', 'es-de', 'daijishou']);
});

it('lays a game out for Recalbox, the mix as its picture', function () {
    $game = superMetroid();
    $target = app(RecalboxTarget::class);
    $xml = simplexml_load_string($target->mergeGamelist(null, $game));

    expect(destinations($target, $game))->toBe([
        'roms/snes/Super Metroid (USA).sfc',
        'roms/snes/media/images/Super Metroid (USA).png',
        'roms/snes/media/screenshots/Super Metroid (USA).png',
        'roms/snes/media/wheels/Super Metroid (USA).png',
        'roms/snes/media/videos/Super Metroid (USA).mp4',
    ])
        ->and($target->plan($game)->files[1]->source->path)->toBe('snes/sm/mixrbv2/x.png')
        ->and($target->plan($game)->gamelist)->toBe('roms/snes/gamelist.xml')
        ->and((string) $xml->game->path)->toBe('./Super Metroid (USA).sfc')
        ->and((string) $xml->game->image)->toBe('./media/images/Super Metroid (USA).png')
        ->and((string) $xml->game->thumbnail)->toBe('./media/screenshots/Super Metroid (USA).png')
        ->and((string) $xml->game->marquee)->toBe('./media/wheels/Super Metroid (USA).png')
        ->and((string) $xml->game->video)->toBe('./media/videos/Super Metroid (USA).mp4')
        ->and((string) $xml->game->rating)->toBe('0.9');
});

it('lays a game out for RetroPie, the box as its picture', function () {
    $game = superMetroid();
    $target = app(RetroPieTarget::class);
    $xml = simplexml_load_string($target->mergeGamelist(null, $game));

    expect(destinations($target, $game))->toBe([
        'roms/snes/Super Metroid (USA).sfc',
        'roms/snes/images/Super Metroid (USA)-image.png',
        'roms/snes/images/Super Metroid (USA)-thumb.png',
        'roms/snes/images/Super Metroid (USA)-marquee.png',
        'roms/snes/videos/Super Metroid (USA)-video.mp4',
    ])
        ->and($target->plan($game)->files[1]->source->path)->toBe('snes/sm/box-2D/x.png')
        ->and((string) $xml->game->image)->toBe('./images/Super Metroid (USA)-image.png')
        ->and((string) $xml->game->video)->toBe('./videos/Super Metroid (USA)-video.mp4');
});

it('lays a game out for ES-DE, artwork by name and a game list without it', function () {
    $game = superMetroid();
    $target = app(EsDeTarget::class);
    $xml = simplexml_load_string($target->mergeGamelist(null, $game));

    expect(destinations($target, $game))->toBe([
        'roms/snes/Super Metroid (USA).sfc',
        'roms/downloaded_media/snes/covers/Super Metroid (USA).png',
        'roms/downloaded_media/snes/screenshots/Super Metroid (USA).png',
        'roms/downloaded_media/snes/titlescreens/Super Metroid (USA).png',
        'roms/downloaded_media/snes/marquees/Super Metroid (USA).png',
        'roms/downloaded_media/snes/miximages/Super Metroid (USA).png',
        'roms/downloaded_media/snes/videos/Super Metroid (USA).mp4',
    ])
        ->and($target->plan($game)->gamelist)->toBe('roms/gamelists/snes/gamelist.xml')
        ->and((string) $xml->game->path)->toBe('./Super Metroid (USA).sfc')
        ->and((string) $xml->game->name)->toBe('Super Metroid')
        ->and($xml->game->image->count() + $xml->game->thumbnail->count() + $xml->game->video->count())->toBe(0);
});

it('lays a game out for Daijishō, in the folders its import asks for', function () {
    $game = superMetroid();
    $target = app(DaijishouTarget::class);
    $xml = simplexml_load_string($target->mergeGamelist(null, $game));

    expect(destinations($target, $game))->toBe([
        'roms/snes/Super Metroid (USA).sfc',
        'roms/snes/media/box2dfront/Super Metroid (USA).png',
        'roms/snes/media/screenshottitle/Super Metroid (USA).png',
        'roms/snes/media/screenshot/Super Metroid (USA).png',
    ])
        ->and($target->plan($game)->gamelist)->toBe('roms/snes/gamelist.xml')
        ->and(array_keys((array) $xml->game))->toBe(['path', 'name', 'desc', 'genre'])
        ->and($target->hint())->toContain('Import Preview Media');
});

it('names ES-DE\'s artwork after the playlist with its folder, and the others\' without', function () {
    $game = finalFantasyIx();

    expect(destinations(app(EsDeTarget::class), $game))->toContain('roms/downloaded_media/psx/covers/Final Fantasy IX/Final Fantasy IX.png')
        ->and(destinations(app(DaijishouTarget::class), $game))->toContain('roms/psx/media/box2dfront/Final Fantasy IX.png')
        ->and(destinations(app(RecalboxTarget::class), $game))->toContain('roms/psx/Final Fantasy IX/Disc 2.chd', 'roms/psx/media/images/Final Fantasy IX.png')
        ->and(app(EsDeTarget::class)->mergeGamelist(null, $game))->toContain('<path>./Final Fantasy IX/Final Fantasy IX.m3u</path>');
});

it('uses each front-end\'s name for a system', function () {
    ConsoleSourceFolder::add(new Console('gc'));
    $game = Game::factory()->forConsole('gc')->matched()->create(['title' => 'Metroid Prime', 'slug' => 'prime']);
    targetFile($game, 'gc/Metroid Prime.rvz', FileRole::Rom);

    expect(destinations(app(BatoceraTarget::class), $game))->toBe(['roms/gamecube/Metroid Prime.rvz'])
        ->and(destinations(app(RecalboxTarget::class), $game))->toBe(['roms/gamecube/Metroid Prime.rvz'])
        ->and(destinations(app(RetroPieTarget::class), $game))->toBe(['roms/gc/Metroid Prime.rvz'])
        ->and(destinations(app(EsDeTarget::class), $game))->toBe(['roms/gc/Metroid Prime.rvz'])
        ->and(app(EsDeTarget::class)->gamelistFor('wswanc'))->toBe('roms/gamelists/wonderswancolor/gamelist.xml')
        ->and(app(RetroPieTarget::class)->gamelistFor('msx2'))->toBe('roms/msx/gamelist.xml');
});

it('knows where each layout starts on a drive, and where a console with nothing to send lists it', function () {
    expect(app(BatoceraTarget::class)->roots())->toBe(['roms', 'batocera/roms'])
        ->and(app(EsDeTarget::class)->roots())->toBe(['roms'])
        ->and(ConsoleTransfer::plan(app(EsDeTarget::class), 'snes')['plan']->gamelist)->toBe('roms/gamelists/snes/gamelist.xml');
});

it('serves an ES-DE plan to the browser', function () {
    $game = superMetroid();

    $this->getJson(route('transfers.plan', ['target' => 'es-de', 'gameId' => $game->id]))
        ->assertOk()
        ->assertJsonPath('files.0.destination', 'roms/snes/Super Metroid (USA).sfc')
        ->assertJsonPath('gamelist', 'roms/gamelists/snes/gamelist.xml');
});

it('offers every layout in Send to, with what each needs done on the other side', function () {
    $game = superMetroid();

    Livewire\Livewire::test('games.show', ['game' => $game])
        ->assertSee('Recalbox')
        ->assertSee('Daijishō')
        ->assertSee('Import Preview Media')
        ->assertSee('Use it as the root of the drive');
});

it('keeps the USB tray in the layout, outside the page, so it outlives navigation', function () {
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-transfer-tray', escape: false)
        ->assertSee(__('Resume'));
});
