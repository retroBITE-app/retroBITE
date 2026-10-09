<?php

use App\Enums\FileRole;
use App\Enums\GameStatus;
use App\Models\AppSetting;
use App\Models\ConsoleSourceFolder;
use App\Models\Destination;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\Media;
use App\Models\Transfer;
use App\Models\User;
use App\Support\Console;
use App\Tools\ConsoleTool\PS2;
use App\Transfers\OplTarget;
use App\Transfers\PlannedFile;
use App\Transfers\Smb\ShareClient;
use App\Transfers\TransferOptions;
use App\Transfers\TransferRejected;
use App\Transfers\TransferTargets;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Fakes\FolderShareClient;

/*
 * Sending a PS2 game laid out for Open PS2 Loader: discs in DVD/ or CD/, and
 * the config and art the PS2 toolbox exports, made for the drive and never
 * written over what is there.
 */

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-opl-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/games/ps2');
    File::ensureDirectoryExists($this->root.'/network/ps2/share');
    config()->set('settings.games_path', $this->root.'/games');
    config()->set('queue.connections.database-long', ['driver' => 'sync']);

    Storage::fake('media');

    $this->shares = new FolderShareClient($this->root.'/network');
    app()->instance(ShareClient::class, $this->shares);

    ConsoleSourceFolder::add(new Console('ps2'));

    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

/** A matched PS2 game; its serial comes with its disc. */
function oplGame(): Game
{
    return Game::factory()->forConsole('ps2')->create([
        'status' => GameStatus::Matched,
        'title' => 'Crash Bandicoot - The Wrath of Cortex',
        'genre' => 'Platform',
    ]);
}

/**
 * A disc on disk and on record: sparse to its size, or with the bytes given.
 * A CSO or ZSO is given a header stating its uncompressed size.
 */
function oplDisc(Game $game, string $path, int $bytes = 4096, ?int $uncompressed = null, ?string $serial = 'SLES_503.86'): GameFile
{
    $absolute = test()->root.'/games/'.$path;
    File::ensureDirectoryExists(dirname($absolute));

    $handle = fopen($absolute, 'wb');

    if ($uncompressed !== null) {
        $magic = Str::lower(pathinfo($path, PATHINFO_EXTENSION)) === 'zso' ? 'ZISO' : 'CISO';
        fwrite($handle, $magic.pack('V', 24).pack('P', $uncompressed).pack('V', 2048));
    }

    ftruncate($handle, $bytes);
    fclose($handle);

    return GameFile::factory()->for($game)->withMeta(['license_id' => $serial])->create([
        'path' => $path,
        'filename' => basename($path),
        'extension' => Str::lower(pathinfo($path, PATHINFO_EXTENSION)),
        'role' => FileRole::Rom,
        'size_bytes' => $bytes,
    ]);
}

/** Artwork of one provider type on the fake media disk: a small red PNG. */
function oplArtwork(Game $game, string $type): Media
{
    $image = imagecreatetruecolor(300, 420);
    imagefilledrectangle($image, 0, 0, 299, 419, (int) imagecolorallocate($image, 200, 30, 30));
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();

    $path = 'ps2/crash/'.$type.'/'.md5($type).'.png';
    Storage::disk('media')->put($path, $png);

    return Media::factory()->for($game)->ofType($type, 'eu')->create(['path' => $path, 'extension' => 'png', 'size_bytes' => strlen($png)]);
}

/** @return list<string> */
function oplDestinations(Game $game): array
{
    return array_map(function (PlannedFile $file): string {
        return $file->destination;
    }, app(OplTarget::class)->plan($game->fresh(['files', 'media']))->files);
}

function oplShareFile(string $path): string
{
    return test()->root.'/network/ps2/share/'.$path;
}

it('keeps a disc in the DVD or CD folder the library already has it in', function () {
    $game = oplGame();
    oplDisc($game, 'ps2/DVD/Crash.iso');

    expect(oplDestinations($game))->toContain('DVD/Crash.iso');

    $small = oplGame();
    oplDisc($small, 'ps2/CD/Small.iso', 900_000_000);

    expect(oplDestinations($small))->toContain('CD/Small.iso');
});

it('files a loose ISO by its size: an 80-minute CD\'s worth to CD, anything bigger to DVD', function () {
    $cd = oplGame();
    oplDisc($cd, 'ps2/Crash.iso', 500_000_000);

    $dvd = oplGame();
    oplDisc($dvd, 'ps2/Okami.iso', 4_000_000_000);

    expect(oplDestinations($cd))->toContain('CD/Crash.iso')
        ->and(oplDestinations($dvd))->toContain('DVD/Okami.iso');
});

it('files a CSO or ZSO by the uncompressed size its header states', function () {
    $cd = oplGame();
    oplDisc($cd, 'ps2/Crash.zso', 4096, uncompressed: 600_000_000);

    $dvd = oplGame();
    oplDisc($dvd, 'ps2/Okami.cso', 4096, uncompressed: 3_000_000_000);

    expect(oplDestinations($cd))->toContain('CD/Crash.zso')
        ->and(oplDestinations($dvd))->toContain('DVD/Okami.cso')
        ->and(OplTarget::discBytes($this->root.'/games/ps2/Okami.cso'))->toBe(3_000_000_000);
});

it('refuses a game with nothing OPL reads, and sends the ISO of one that also has a CHD', function () {
    $chd = oplGame();
    oplDisc($chd, 'ps2/Crash.chd');

    expect(function () use ($chd) {
        app(OplTarget::class)->plan($chd->fresh(['files', 'media']));
    })->toThrow(TransferRejected::class, 'Open PS2 Loader reads ISO, CSO or ZSO. Convert this game first.');

    $both = oplGame();
    oplDisc($both, 'ps2/Okami.chd');
    oplDisc($both, 'ps2/DVD/Okami.iso');

    expect(oplDestinations($both))->toContain('DVD/Okami.iso')->not->toContain('DVD/Okami.chd');
});

it('adds the config and each piece of art the game has, named after its serial, and keeps no game list', function () {
    $game = oplGame();
    oplDisc($game, 'ps2/DVD/Crash.iso');
    oplArtwork($game, 'box-2D');
    oplArtwork($game, 'ss');

    $plan = app(OplTarget::class)->plan($game->fresh(['files', 'media']));

    expect($plan->gamelist)->toBeNull()
        ->and(collect($plan->extras)->pluck('destination')->all())->toBe(['CFG/SLES_503.86.cfg', 'ART/SLES_503.86_COV.png', 'ART/SLES_503.86_SCR.png'])
        ->and(Arr::first($plan->extras))->toBe([
            'url' => route('transfers.extra', ['target' => 'opl', 'gameId' => $game->id, 'path' => 'CFG/SLES_503.86.cfg']),
            'destination' => 'CFG/SLES_503.86.cfg',
        ])
        ->and($plan->toArray()['gamelist'])->toBeNull();
});

it('sends only the discs of a game with no serial', function () {
    $game = oplGame();
    oplDisc($game, 'ps2/DVD/Crash.iso', serial: null);
    oplArtwork($game, 'box-2D');

    expect(oplDestinations($game))->toBe(['DVD/Crash.iso'])
        ->and(app(OplTarget::class)->plan($game->fresh(['files', 'media']))->extras)->toBe([]);
});

it('is offered for PS2 only, and first where the library is laid out for OPL', function () {
    $ps2 = new Console('ps2');
    $snes = new Console('snes');

    $frontEnds = ['batocera', 'recalbox', 'retropie', 'es-de', 'daijishou'];

    expect(collect(TransferTargets::for($snes))->map->key()->all())->toBe($frontEnds)
        ->and(collect(TransferTargets::for($ps2))->map->key()->all())->toBe([...$frontEnds, 'opl'])
        ->and(TransferTargets::recommendedFor($ps2)?->key())->toBe('batocera');

    ConsoleSourceFolder::setLayout($ps2, 'opl');

    expect(TransferTargets::recommendedFor($ps2)?->key())->toBe('opl');

    $game = oplGame();
    oplDisc($game, 'ps2/DVD/Crash.iso');

    $this->get(route('games.show', $game->routeParameters()))
        ->assertOk()
        ->assertSee('Open PS2 Loader')
        ->assertSee('\\u0022target\\u0022:\\u0022opl\\u0022', false);
});

/** The browser's request for one of a game's extras. */
function oplExtra(int $gameId, string $path, string $target = 'opl'): TestResponse
{
    return test()->get(route('transfers.extra', ['target' => $target, 'gameId' => $gameId, 'path' => $path]));
}

it('makes the config and art for the browser as it asks, the same the toolbox exports', function () {
    $game = oplGame();
    oplDisc($game, 'ps2/DVD/Crash.iso');
    oplArtwork($game, 'box-2D');
    $game = $game->fresh(['files', 'media']);

    $config = oplExtra($game->id, 'CFG/SLES_503.86.cfg')->assertOk();
    $cover = oplExtra($game->id, 'ART/SLES_503.86_COV.png')->assertOk();

    expect($config->getContent())->toBe(app(PS2::class)->configFor($game))
        ->and(getimagesizefromstring((string) $cover->getContent()))->toMatchArray([0 => 256, 1 => 368]);

    // Nothing the target does not list for this game: no disc art, another
    // serial, a path out, another target, another game.
    oplExtra($game->id, 'ART/SLES_503.86_ICO.png')->assertNotFound();
    oplExtra($game->id, 'CFG/SLUS_999.99.cfg')->assertNotFound();
    oplExtra($game->id, '../../.env')->assertNotFound();
    oplExtra($game->id, 'CFG/SLES_503.86.cfg', 'batocera')->assertNotFound();
    oplExtra(999999, 'CFG/SLES_503.86.cfg')->assertNotFound();

    auth()->logout();
    oplExtra($game->id, 'CFG/SLES_503.86.cfg')->assertRedirect();
});

it('plans a console for OPL: discs, config and art at the top of the drive, no game list', function () {
    $game = oplGame();
    oplDisc($game, 'ps2/DVD/Crash.iso');
    oplArtwork($game, 'box-2D');

    $plan = $this->getJson(route('transfers.console-plan', ['target' => 'opl', 'console' => 'ps2']))->assertOk()->json();

    expect(collect($plan['files'])->pluck('destination')->all())->toBe(['DVD/Crash.iso'])
        ->and(collect($plan['extras'])->pluck('destination')->all())->toBe(['CFG/SLES_503.86.cfg', 'ART/SLES_503.86_COV.png'])
        ->and($plan['gamelist'])->toBeNull();
});

it('sends to a share laid out for OPL, leaving a config already there as it was', function () {
    $game = oplGame();
    oplDisc($game, 'ps2/DVD/Crash.iso');
    oplArtwork($game, 'box-2D');

    File::ensureDirectoryExists(oplShareFile('CFG'));
    File::put(oplShareFile('CFG/SLES_503.86.cfg'), "\$DMA=7\n");

    $destination = Destination::query()->create(['name' => 'PS2', 'host' => 'ps2', 'share' => 'share']);

    Livewire::test('games.show', ['game' => $game])
        ->call('sendToShare', $destination->id, 'opl');

    expect(File::size(oplShareFile('DVD/Crash.iso')))->toBe(4096)
        ->and(File::get(oplShareFile('CFG/SLES_503.86.cfg')))->toBe("\$DMA=7\n")
        ->and(getimagesize(oplShareFile('ART/SLES_503.86_COV.png')))->toMatchArray([0 => 256, 1 => 368])
        ->and(File::glob(oplShareFile('*.xml')))->toBe([])
        ->and(Transfer::query()->sole())
        ->status->toBe(Transfer::DONE)
        ->target->toBe('opl');
});

it('still refuses a disc of another size at the same name: only extras are left as they are', function () {
    $game = oplGame();
    oplDisc($game, 'ps2/DVD/Crash.iso');

    File::ensureDirectoryExists(oplShareFile('DVD'));
    File::put(oplShareFile('DVD/Crash.iso'), 'something else');

    $destination = Destination::query()->create(['name' => 'PS2', 'host' => 'ps2', 'share' => 'share']);

    Livewire::test('games.show', ['game' => $game])
        ->call('sendToShare', $destination->id, 'opl');

    expect(File::get(oplShareFile('DVD/Crash.iso')))->toBe('something else')
        ->and(Transfer::query()->sole()->status)->toBe(Transfer::FAILED);
});

it('sends only the art chosen for the transfer, and makes no other piece when asked', function () {
    $game = oplGame();
    oplDisc($game, 'ps2/DVD/Crash.iso');
    oplArtwork($game, 'box-2D');
    oplArtwork($game, 'ss');

    $target = app(OplTarget::class)->withOptions(new TransferOptions(artwork: ['cover']));
    $plan = $target->plan($game->fresh(['files', 'media']));

    expect(array_keys(app(OplTarget::class)->artworkSlots()))->toBe(['cover', 'disc', 'screenshot', 'title_screen'])
        ->and(collect($plan->extras)->pluck('destination')->all())->toBe(['CFG/SLES_503.86.cfg', 'ART/SLES_503.86_COV.png'])
        // The browser asks for each extra by URL, and the URL carries the choice.
        ->and(Arr::first($plan->extras)['url'])->toContain('artwork=cover')
        ->and($target->extra($game->fresh(['files', 'media']), 'ART/SLES_503.86_SCR.png'))->toBeNull();
});

it('replaces another disc of the game OPL could read, and leaves its config and art', function () {
    $game = oplGame();
    oplDisc($game, 'ps2/DVD/Crash (Europe).iso');
    oplDisc($game, 'ps2/DVD/Crash (USA).iso', serial: 'SLUS_203.44');
    oplDisc($game, 'ps2/Crash (Japan).chd');
    AppSetting::put(AppSetting::TRANSFER_REGIONS, ['us', 'eu']);

    $plan = app(OplTarget::class)->plan($game->fresh(['files', 'media']));

    expect(array_map(fn (PlannedFile $file): string => $file->destination, $plan->files))->toBe(['DVD/Crash (USA).iso'])
        // Not the CHD: OPL never read it, so it was never sent.
        ->and($plan->replaces)->toBe(['DVD/Crash (Europe).iso']);
});
