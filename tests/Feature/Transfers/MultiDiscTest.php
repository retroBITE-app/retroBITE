<?php

use App\Enums\FileRole;
use App\Models\ConsoleSourceFolder;
use App\Models\Destination;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\Media;
use App\Models\Transfer;
use App\Models\User;
use App\Support\Console;
use App\Transfers\BatoceraTarget;
use App\Transfers\Smb\ShareClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Fakes\FolderShareClient;

/*
 * A game of several discs, sent to a front-end that plays an .m3u: into a
 * folder of its own with a playlist written for it, so RetroArch can change
 * discs and the list shows one game (docs/adr/0008). Single discs, and
 * consoles nobody plays a playlist on, go as they always did.
 */

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-discs-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/games/psx');
    File::ensureDirectoryExists($this->root.'/network/batocera/share');
    config()->set('settings.games_path', $this->root.'/games');
    config()->set('queue.connections.database-long', ['driver' => 'sync']);

    app()->instance(ShareClient::class, new FolderShareClient($this->root.'/network'));

    Storage::fake('media');
    Storage::disk('media')->put('psx/ff7/box-2D/x.png', 'png-bytes');

    ConsoleSourceFolder::add(new Console('psx'));

    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function discFile(Game $game, string $path, FileRole $role, ?int $disc = null, ?GameFile $parent = null): GameFile
{
    File::ensureDirectoryExists(dirname(test()->root.'/games/'.$path));
    File::put(test()->root.'/games/'.$path, 'disc-bytes');

    return GameFile::factory()->for($game)->create([
        'path' => $path,
        'filename' => basename($path),
        'extension' => pathinfo($path, PATHINFO_EXTENSION),
        'role' => $role,
        'disc_number' => $disc,
        'parent_id' => $parent?->id,
        'size_bytes' => 10,
    ]);
}

/** Final Fantasy VII as the scan leaves loose cuesheets: two sheets, a track each, no playlist. */
function looseDiscs(string $console = 'psx'): Game
{
    $game = Game::factory()->forConsole($console)->matched()->create(['title' => 'Final Fantasy VII', 'slug' => 'ff7']);

    foreach ([1, 2] as $disc) {
        $sheet = discFile($game, "{$console}/Final Fantasy VII (USA) (Disc {$disc}).cue", FileRole::Sheet, $disc);
        discFile($game, "{$console}/Final Fantasy VII (USA) (Disc {$disc}).bin", FileRole::Track, $disc, $sheet);
    }

    Media::factory()->for($game)->ofType('box-2D', 'us')->create(['path' => 'psx/ff7/box-2D/x.png', 'extension' => 'png', 'size_bytes' => 10]);

    return $game->fresh();
}

function shareDiscFile(string $path): string
{
    return test()->root.'/network/batocera/share/'.$path;
}

it('puts loose discs in a folder named for the set, with a playlist of their sheets', function () {
    $game = looseDiscs();
    $target = app(BatoceraTarget::class);
    $plan = $target->plan($game)->toArray();
    $folder = 'roms/psx/Final Fantasy VII (USA)';

    expect(array_column($plan['files'], 'destination'))->toBe([
        "{$folder}/Final Fantasy VII (USA) (Disc 1).bin",
        "{$folder}/Final Fantasy VII (USA) (Disc 1).cue",
        "{$folder}/Final Fantasy VII (USA) (Disc 2).bin",
        "{$folder}/Final Fantasy VII (USA) (Disc 2).cue",
        'roms/psx/images/Final Fantasy VII (USA)-thumb.png',
    ])
        ->and($plan['extras'])->toBe([[
            'url' => route('transfers.extra', ['target' => 'batocera', 'gameId' => $game->id, 'path' => "{$folder}/Final Fantasy VII (USA).m3u"]),
            'destination' => "{$folder}/Final Fantasy VII (USA).m3u",
        ]])
        ->and($target->extra($game, "{$folder}/Final Fantasy VII (USA).m3u"))
        ->toBe("Final Fantasy VII (USA) (Disc 1).cue\nFinal Fantasy VII (USA) (Disc 2).cue\n")
        ->and($target->mergeGamelist(null, $game))
        ->toContain('<path>./Final Fantasy VII (USA)/Final Fantasy VII (USA).m3u</path>')
        ->toContain('<thumbnail>./images/Final Fantasy VII (USA)-thumb.png</thumbnail>');
});

it('orders the playlist by disc, ten after two', function () {
    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Long', 'slug' => 'long']);

    foreach ([10, 2, 1] as $disc) {
        discFile($game, "psx/Long (Disc {$disc}).chd", FileRole::Rom, $disc);
    }

    expect(app(BatoceraTarget::class)->extra($game->fresh(), 'roms/psx/Long/Long.m3u'))
        ->toBe("Long (Disc 1).chd\nLong (Disc 2).chd\nLong (Disc 10).chd\n");
});

it('removes the set as it went before, loose beside the other games, once it has arrived', function () {
    $game = looseDiscs();
    $target = app(BatoceraTarget::class);

    expect($target->plan($game)->replaces)->toContain(
        'roms/psx/Final Fantasy VII (USA) (Disc 1).cue',
        'roms/psx/Final Fantasy VII (USA) (Disc 2).bin',
        'roms/psx/images/Final Fantasy VII (USA) (Disc 1)-thumb.png',
    );

    $old = '<?xml version="1.0"?><gameList>'
        .'<game><path>./Final Fantasy VII (USA) (Disc 1).cue</path><playcount>3</playcount></game>'
        .'<game><path>./Tekken 3.cue</path></game></gameList>';

    expect($target->mergeGamelist($old, $game))
        ->not->toContain('(Disc 1).cue</path>')
        ->toContain('<path>./Tekken 3.cue</path>')
        ->toContain('<path>./Final Fantasy VII (USA)/Final Fantasy VII (USA).m3u</path>');
});

it('sends a single disc as it always did', function () {
    $game = Game::factory()->forConsole('psx')->matched()->create(['title' => 'Tekken 3', 'slug' => 'tekken-3']);
    $sheet = discFile($game, 'psx/Tekken 3.cue', FileRole::Sheet);
    discFile($game, 'psx/Tekken 3.bin', FileRole::Track, null, $sheet);

    $plan = app(BatoceraTarget::class)->plan($game->fresh());

    expect(array_column($plan->toArray()['files'], 'destination'))->toBe(['roms/psx/Tekken 3.bin', 'roms/psx/Tekken 3.cue'])
        ->and($plan->extras)->toBe([])
        ->and($plan->replaces)->toBe([]);
});

it('sends discs as the library has them where the front-end plays no playlist', function () {
    ConsoleSourceFolder::add(new Console('ps2'));
    $game = Game::factory()->forConsole('ps2')->matched()->create(['title' => 'Xenosaga', 'slug' => 'xenosaga']);
    discFile($game, 'ps2/Xenosaga (Disc 1).iso', FileRole::Rom, 1);
    discFile($game, 'ps2/Xenosaga (Disc 2).iso', FileRole::Rom, 2);

    $plan = app(BatoceraTarget::class)->plan($game->fresh());

    expect(array_column($plan->toArray()['files'], 'destination'))->toBe(['roms/ps2/Xenosaga (Disc 1).iso', 'roms/ps2/Xenosaga (Disc 2).iso'])
        ->and($plan->extras)->toBe([]);
});

it('makes the playlist for the browser, and nothing else under its name', function () {
    $game = looseDiscs();
    $path = 'roms/psx/Final Fantasy VII (USA)/Final Fantasy VII (USA).m3u';

    $response = $this->get(route('transfers.extra', ['target' => 'batocera', 'gameId' => $game->id, 'path' => $path]))->assertOk();

    expect($response->getContent())->toBe("Final Fantasy VII (USA) (Disc 1).cue\nFinal Fantasy VII (USA) (Disc 2).cue\n");

    $this->get(route('transfers.extra', ['target' => 'batocera', 'gameId' => $game->id, 'path' => 'roms/psx/Other/Other.m3u']))->assertNotFound();
    $this->get(route('transfers.extra', ['target' => 'daijishou', 'gameId' => $game->id, 'path' => $path]))->assertNotFound();
});

it('writes the folder and its playlist to a share, and lists the game once', function () {
    $game = looseDiscs();
    $destination = Destination::query()->create(['name' => 'Living room', 'host' => 'batocera', 'share' => 'share']);

    // Sent before sets had folders: loose, and listed by their first disc.
    File::ensureDirectoryExists(shareDiscFile('roms/psx'));
    File::put(shareDiscFile('roms/psx/Final Fantasy VII (USA) (Disc 1).cue'), 'disc-bytes');
    File::put(shareDiscFile('roms/psx/gamelist.xml'), '<?xml version="1.0"?><gameList><game><path>./Final Fantasy VII (USA) (Disc 1).cue</path></game></gameList>');

    Livewire::test('games.show', ['game' => $game])->call('sendToShare', $destination->id, 'batocera');

    $folder = 'roms/psx/Final Fantasy VII (USA)';
    $xml = simplexml_load_string(File::get(shareDiscFile('roms/psx/gamelist.xml')));

    expect(Transfer::query()->sole()->status)->toBe(Transfer::DONE)
        ->and(File::get(shareDiscFile("{$folder}/Final Fantasy VII (USA).m3u")))->toBe("Final Fantasy VII (USA) (Disc 1).cue\nFinal Fantasy VII (USA) (Disc 2).cue\n")
        ->and(File::exists(shareDiscFile("{$folder}/Final Fantasy VII (USA) (Disc 2).bin")))->toBeTrue()
        ->and(File::get(shareDiscFile('roms/psx/images/Final Fantasy VII (USA)-thumb.png')))->toBe('png-bytes')
        ->and(File::exists(shareDiscFile('roms/psx/Final Fantasy VII (USA) (Disc 1).cue')))->toBeFalse()
        ->and(array_map('strval', $xml->xpath('/gameList/game/path')))->toBe(['./Final Fantasy VII (USA)/Final Fantasy VII (USA).m3u']);
});
