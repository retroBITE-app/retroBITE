<?php

use App\Enums\FileRole;
use App\Models\AppSetting;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\Media;
use App\Models\User;
use App\Support\Console;
use App\Transfers\BatoceraTarget;
use App\Transfers\GameVersions;
use App\Transfers\TransferRejected;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * Sending a game to a Batocera drive. The server decides everything that is
 * written; these pin down the layout, the game list, and that the library is
 * only ever read.
 */

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-transfer-'.Str::random(8);
    File::ensureDirectoryExists($this->root);
    config()->set('settings.games_path', $this->root);

    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

/** A file of the game's, on disk under the library root, with its row. */
function libraryFile(Game $game, string $path, FileRole $role, ?int $disc = null, string $bytes = 'rom-bytes'): GameFile
{
    File::ensureDirectoryExists(dirname(test()->root.'/'.$path));
    File::put(test()->root.'/'.$path, $bytes);

    return GameFile::factory()->for($game)->create([
        'path' => $path,
        'filename' => basename($path),
        'extension' => pathinfo($path, PATHINFO_EXTENSION),
        'role' => $role,
        'disc_number' => $disc,
        'size_bytes' => strlen($bytes),
    ]);
}

function snesGame(array $attributes = []): Game
{
    ConsoleSourceFolder::add(new Console('snes'));

    return Game::factory()->forConsole('snes')->matched()->create($attributes + ['title' => 'Super Mario World', 'slug' => 'smw']);
}

it('lays a single-file game out under roms/{system}', function () {
    $game = snesGame();
    $rom = libraryFile($game, 'snes/Super Mario World (USA).sfc', FileRole::Rom);

    $plan = app(BatoceraTarget::class)->plan($game)->toArray();

    expect($plan['files'])->toBe([[
        'url' => route('transfers.files', ['file' => $rom->id]),
        'destination' => 'roms/snes/Super Mario World (USA).sfc',
        'size' => 9,
    ]])
        ->and($plan['gamelist'])->toBe('roms/snes/gamelist.xml')
        ->and($plan['bytes'])->toBe(9);
});

it('keeps a multi-disc set as it is in the library, and points the list at its playlist', function () {
    ConsoleSourceFolder::add(new Console('psx'));
    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Final Fantasy IX', 'slug' => 'ff9']);

    // Linked as the scanner links them: the discs under the playlist, each
    // track under its sheet.
    $playlist = libraryFile($game, 'psx/Final Fantasy IX/Final Fantasy IX.m3u', FileRole::Playlist);
    $one = libraryFile($game, 'psx/Final Fantasy IX/Disc 1.cue', FileRole::Sheet, 1);
    $one->update(['parent_id' => $playlist->id]);
    libraryFile($game, 'psx/Final Fantasy IX/Disc 1.bin', FileRole::Track, 1)->update(['parent_id' => $one->id]);
    $two = libraryFile($game, 'psx/Final Fantasy IX/Disc 2.cue', FileRole::Sheet, 2);
    $two->update(['parent_id' => $playlist->id]);
    libraryFile($game, 'psx/Final Fantasy IX/Disc 2.bin', FileRole::Track, 2)->update(['parent_id' => $two->id]);

    $target = app(BatoceraTarget::class);

    expect(array_column($target->plan($game)->toArray()['files'], 'destination'))->toBe([
        'roms/psx/Final Fantasy IX/Disc 1.bin',
        'roms/psx/Final Fantasy IX/Disc 1.cue',
        'roms/psx/Final Fantasy IX/Disc 2.bin',
        'roms/psx/Final Fantasy IX/Disc 2.cue',
        'roms/psx/Final Fantasy IX/Final Fantasy IX.m3u',
    ])->and($target->mergeGamelist(null, $game))->toContain('<path>./Final Fantasy IX/Final Fantasy IX.m3u</path>');
});

it('sends one version of a game that holds several, and only lists that one', function () {
    $game = snesGame(['title' => 'Aerostar', 'slug' => 'aerostar']);
    libraryFile($game, 'snes/Aerostar (Japan).sfc', FileRole::Rom);
    libraryFile($game, 'snes/Aerostar (USA, Europe) (Fr).sfc', FileRole::Rom);
    libraryFile($game, 'snes/Aerostar (USA, Europe).sfc', FileRole::Rom);

    $target = app(BatoceraTarget::class);

    // Europe before Japan, and the plain copy before the French one.
    expect(array_column($target->plan($game->fresh())->toArray()['files'], 'destination'))->toBe(['roms/snes/Aerostar (USA, Europe).sfc'])
        ->and($target->mergeGamelist(null, $game->fresh()))->toContain('<path>./Aerostar (USA, Europe).sfc</path>')
        ->not->toContain('Japan');
});

it('puts the library\'s own region first, then the latest revision, and never a pre-release', function () {
    AppSetting::put(AppSetting::MEDIA_REGION, 'jp');
    $game = snesGame();
    libraryFile($game, 'snes/Game (USA).sfc', FileRole::Rom);
    libraryFile($game, 'snes/Game (Japan).sfc', FileRole::Rom);
    libraryFile($game, 'snes/Game (Japan) (Rev 1).sfc', FileRole::Rom);
    libraryFile($game, 'snes/Game (Japan) (Rev 2) (Beta).sfc', FileRole::Rom);

    expect(array_map(fn ($file) => $file->filename, GameVersions::preferred($game->fresh())))->toBe(['Game (Japan) (Rev 1).sfc']);
});

it('keeps loose discs of one set together when no playlist names them', function () {
    ConsoleSourceFolder::add(new Console('psx'));
    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Final Fantasy VII', 'slug' => 'ff7']);
    libraryFile($game, 'psx/Final Fantasy VII (Europe) (Disc 1).chd', FileRole::Rom, 1);
    libraryFile($game, 'psx/Final Fantasy VII (Europe) (Disc 2).chd', FileRole::Rom, 2);
    libraryFile($game, 'psx/Final Fantasy VII (USA) (Disc 1).chd', FileRole::Rom, 1);
    libraryFile($game, 'psx/Final Fantasy VII (USA) (Disc 2).chd', FileRole::Rom, 2);

    expect(array_map(fn ($file) => $file->filename, GameVersions::preferred($game->fresh())))->toBe([
        'Final Fantasy VII (Europe) (Disc 1).chd',
        'Final Fantasy VII (Europe) (Disc 2).chd',
    ]);
});

it('sends the cover beside the game, named after the file the list points at', function () {
    $game = snesGame();
    libraryFile($game, 'snes/smw.sfc', FileRole::Rom);
    $cover = Media::factory()->for($game)->ofType('box-2D', 'us')->create(['path' => 'snes/smw/box-2d/abc.png', 'extension' => 'png', 'size_bytes' => 1234]);

    $target = app(BatoceraTarget::class);
    $files = $target->plan($game)->toArray()['files'];

    expect(end($files))->toBe([
        'url' => $cover->url(),
        'destination' => 'roms/snes/images/smw-thumb.png',
        'size' => 1234,
    ])->and($target->mergeGamelist(null, $game))->toContain('<thumbnail>./images/smw-thumb.png</thumbnail>');
});

it('sends every kind of artwork Batocera shows, each under its own suffix and tag', function () {
    $game = snesGame();
    libraryFile($game, 'snes/smw.sfc', FileRole::Rom);

    $art = [
        'box-3D' => 'png', 'box-2D' => 'png', 'ss' => 'png', 'screenmarquee' => 'png', 'wheel' => 'png', 'fanart' => 'jpg',
        'box-2D-back' => 'png', 'support-2D' => 'png', 'sstitle' => 'png', 'mixrbv2' => 'png', 'manuel' => 'pdf',
    ];

    foreach ($art as $type => $extension) {
        Media::factory()->for($game)->ofType($type, 'us')->create(['path' => "snes/smw/{$type}/x.{$extension}", 'extension' => $extension, 'size_bytes' => 10]);
    }

    $target = app(BatoceraTarget::class);
    $plan = $target->plan($game->fresh());
    $xml = simplexml_load_string($target->mergeGamelist(null, $game->fresh()));

    expect(array_slice(array_column($plan->toArray()['files'], 'destination'), 1))->toBe([
        'roms/snes/images/smw-thumb.png',
        'roms/snes/images/smw-image.png',
        'roms/snes/images/smw-marquee.png',
        'roms/snes/images/smw-fanart.jpg',
        'roms/snes/images/smw-boxback.png',
        'roms/snes/images/smw-cartridge.png',
        'roms/snes/images/smw-titleshot.png',
        'roms/snes/images/smw-mix.png',
        'roms/snes/images/smw-manual.pdf',
    ])
        // The preferred type of each: the flat box over the 3D one, the logo over the marquee banner.
        ->and($plan->files[1]->source->path)->toBe('snes/smw/box-2D/x.png')
        ->and($plan->files[3]->source->path)->toBe('snes/smw/wheel/x.png')
        // Backwards, as EmulationStation has them: the box is the thumbnail.
        ->and((string) $xml->game->thumbnail)->toBe('./images/smw-thumb.png')
        ->and((string) $xml->game->image)->toBe('./images/smw-image.png')
        ->and($plan->files[2]->source->path)->toBe('snes/smw/ss/x.png')
        ->and((string) $xml->game->marquee)->toBe('./images/smw-marquee.png')
        ->and((string) $xml->game->fanart)->toBe('./images/smw-fanart.jpg')
        ->and((string) $xml->game->boxback)->toBe('./images/smw-boxback.png')
        ->and((string) $xml->game->cartridge)->toBe('./images/smw-cartridge.png')
        ->and((string) $xml->game->titleshot)->toBe('./images/smw-titleshot.png')
        ->and((string) $xml->game->mix)->toBe('./images/smw-mix.png')
        ->and((string) $xml->game->manual)->toBe('./images/smw-manual.pdf');
});

it('never puts a mix in the image, where a theme building its own mix would show the box and logo twice', function () {
    $game = snesGame();
    libraryFile($game, 'snes/smw.sfc', FileRole::Rom);
    Media::factory()->for($game)->ofType('mixrbv2', 'us')->create(['path' => 'snes/smw/mixrbv2/x.png', 'extension' => 'png', 'size_bytes' => 10]);

    $target = app(BatoceraTarget::class);
    $xml = simplexml_load_string($target->mergeGamelist(null, $game->fresh()));

    expect(array_column($target->plan($game->fresh())->toArray()['files'], 'destination'))->toBe([
        'roms/snes/smw.sfc',
        'roms/snes/images/smw-mix.png',
    ])
        ->and($xml->game->image->count())->toBe(0)
        ->and((string) $xml->game->mix)->toBe('./images/smw-mix.png');
});

it('names a title screen standing in for a screenshot as a title shot, and sends it once for both tags', function () {
    $game = snesGame();
    libraryFile($game, 'snes/smw.sfc', FileRole::Rom);
    Media::factory()->for($game)->ofType('sstitle', 'us')->create(['path' => 'snes/smw/sstitle/x.png', 'extension' => 'png', 'size_bytes' => 10]);

    $target = app(BatoceraTarget::class);
    $xml = simplexml_load_string($target->mergeGamelist(null, $game->fresh()));

    expect(array_column($target->plan($game->fresh())->toArray()['files'], 'destination'))->toBe([
        'roms/snes/smw.sfc',
        'roms/snes/images/smw-titleshot.png',
    ])
        ->and((string) $xml->game->image)->toBe('./images/smw-titleshot.png')
        ->and((string) $xml->game->titleshot)->toBe('./images/smw-titleshot.png');
});

it('leaves out artwork that was never downloaded, tag and all', function () {
    $game = snesGame();
    libraryFile($game, 'snes/smw.sfc', FileRole::Rom);
    Media::factory()->for($game)->ofType('fanart', 'us')->create(['path' => 'snes/smw/fanart/x.jpg', 'extension' => 'jpg', 'size_bytes' => 10]);

    $xml = simplexml_load_string(app(BatoceraTarget::class)->mergeGamelist(null, $game->fresh()));

    expect((string) $xml->game->fanart)->toBe('./images/smw-fanart.jpg')
        ->and($xml->game->thumbnail->count())->toBe(0)
        ->and($xml->game->manual->count())->toBe(0);
});

it('writes the game list Batocera reads, from what the provider said', function () {
    $game = snesGame([
        'description' => 'Mario & Luigi <3',
        'rating' => 80,
        'release_date' => '1990-11-21',
        'developer' => 'Nintendo EAD',
        'publisher' => 'Nintendo',
        'genre' => 'Platform, Adventure',
        'players' => '1-2',
    ]);
    libraryFile($game, 'snes/smw.sfc', FileRole::Rom);

    $xml = simplexml_load_string(app(BatoceraTarget::class)->mergeGamelist(null, $game));

    expect($xml->getName())->toBe('gameList')
        ->and((string) $xml->game->path)->toBe('./smw.sfc')
        ->and((string) $xml->game->name)->toBe('Super Mario World')
        ->and((string) $xml->game->desc)->toBe('Mario & Luigi <3')
        // Out of a hundred here, from 0 to 1 there.
        ->and((string) $xml->game->rating)->toBe('0.8')
        ->and((string) $xml->game->releasedate)->toBe('19901121T000000')
        ->and((string) $xml->game->genre)->toBe('Platform')
        ->and((string) $xml->game->players)->toBe('2');
});

it('updates its own entry and leaves everyone else\'s alone', function () {
    $game = snesGame();
    libraryFile($game, 'snes/smw.sfc', FileRole::Rom);

    $existing = <<<'XML'
        <?xml version="1.0"?>
        <gameList>
          <game><path>./zelda.sfc</path><name>Zelda</name><playcount>12</playcount></game>
          <game><path>./smw.sfc</path><name>Old name</name></game>
        </gameList>
        XML;

    $xml = simplexml_load_string(app(BatoceraTarget::class)->mergeGamelist($existing, $game));

    expect($xml->game)->toHaveCount(2)
        ->and((string) $xml->game[0]->playcount)->toBe('12')
        ->and((string) $xml->game[1]->name)->toBe('Super Mario World');
});

it('refuses rather than replaces a game list it cannot read', function () {
    $game = snesGame();
    libraryFile($game, 'snes/smw.sfc', FileRole::Rom);

    expect(fn () => app(BatoceraTarget::class)->mergeGamelist('<gameList><game>', $game))->toThrow(TransferRejected::class);

    $this->call('POST', route('transfers.gamelist', ['target' => 'batocera', 'gameId' => $game->id]), [], [], [], ['CONTENT_TYPE' => 'application/xml'], '<gameList><game>')
        ->assertStatus(422);
});

it('serves the plan and the merged list to the browser', function () {
    $game = snesGame();
    libraryFile($game, 'snes/smw.sfc', FileRole::Rom);

    $this->getJson(route('transfers.plan', ['target' => 'batocera', 'gameId' => $game->id]))
        ->assertOk()
        ->assertJsonPath('files.0.destination', 'roms/snes/smw.sfc');

    $this->call('POST', route('transfers.gamelist', ['target' => 'batocera', 'gameId' => $game->id]), [], [], [], ['CONTENT_TYPE' => 'application/xml'], '')
        ->assertOk()
        ->assertSee('<path>./smw.sfc</path>', escape: false);

    $this->getJson('/transfers/nowhere/games/'.$game->id)->assertNotFound();
});

it('hands over a library file as it is, and only one a row points at', function () {
    $game = snesGame();
    $rom = libraryFile($game, 'snes/smw.sfc', FileRole::Rom, bytes: 'the exact bytes');

    $response = $this->get(route('transfers.files', ['file' => $rom->id]))->assertOk();

    expect($response->baseResponse->getFile()->getContent())->toBe('the exact bytes')
        ->and(File::get($this->root.'/snes/smw.sfc'))->toBe('the exact bytes');

    $rom->update(['missing_since' => now()]);
    $this->get(route('transfers.files', ['file' => $rom->id]))->assertNotFound();
    $this->get('/transfers/files/999999')->assertNotFound();
});

it('leaves the bytes to nginx where it is in front, with the path spelled for it', function () {
    config()->set('settings.serve_with_nginx', true);
    $game = snesGame();
    $rom = libraryFile($game, "snes/Bill & Ted's #1 (USA).sfc", FileRole::Rom, bytes: 'the exact bytes');

    // The same checks first: a file gone is still a 404, never a redirect.
    $this->get(route('transfers.files', ['file' => $rom->id]))
        ->assertOk()
        ->assertHeader('X-Accel-Redirect', '/_serve/games/snes/Bill%20%26%20Ted%27s%20%231%20%28USA%29.sfc')
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertContent('');

    $rom->update(['missing_since' => now()]);
    $this->get(route('transfers.files', ['file' => $rom->id]))->assertNotFound();
});

it('lets nobody who is not signed in near it', function () {
    $game = snesGame();
    $rom = libraryFile($game, 'snes/smw.sfc', FileRole::Rom);
    auth()->logout();

    $this->get(route('transfers.plan', ['target' => 'batocera', 'gameId' => $game->id]))->assertRedirect(route('login'));
    $this->get(route('transfers.files', ['file' => $rom->id]))->assertRedirect(route('login'));
});
