<?php

use App\Enums\FileRole;
use App\Models\AppSetting;
use App\Models\ConsoleSourceFolder;
use App\Models\Destination;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\Media;
use App\Models\Transfer;
use App\Models\User;
use App\Support\Console;
use App\Support\ConsoleOverrides;
use App\Support\TransferRegions;
use App\Transfers\BatoceraTarget;
use App\Transfers\GameVersions;
use App\Transfers\SendToShare;
use App\Transfers\Smb\ShareClient;
use App\Transfers\TransferOptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Fakes\FolderShareClient;

/*
 * What one transfer can choose beyond its target: the region to send first,
 * falling back on the console's region order and then the library's, and
 * which of the target's artwork goes at all — for a drive, through the
 * plan's URLs, and for a share, through the Transfer row.
 */

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-options-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/games/snes');
    File::ensureDirectoryExists($this->root.'/network/batocera/share');
    config()->set('settings.games_path', $this->root.'/games');
    config()->set('queue.connections.database-long', ['driver' => 'sync']);

    app()->instance(ShareClient::class, new FolderShareClient($this->root.'/network'));

    ConsoleSourceFolder::add(new Console('snes'));

    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    ConsoleOverrides::forgetAll();
    File::deleteDirectory($this->root);
});

/** A matched SNES game holding a file per name given, each with its region as recorded. */
function regionalGame(array $files, string $title = 'Aerostar'): Game
{
    $game = Game::factory()->forConsole('snes')->matched()->create(['title' => $title, 'slug' => Str::slug($title)]);

    foreach ($files as $name => $region) {
        File::put(test()->root.'/games/snes/'.$name, $name);

        GameFile::factory()->for($game)->create([
            'path' => 'snes/'.$name, 'filename' => $name, 'extension' => 'sfc', 'role' => FileRole::Rom,
            'size_bytes' => strlen($name), 'region' => $region,
        ]);
    }

    return $game->fresh(['files', 'media']);
}

/** @return list<string> */
/** @param  list<string>  $regions  the order sorted in Send to; none for the console's */
function sentNames(Game $game, array $regions = []): array
{
    return array_map(fn (GameFile $file): string => $file->filename, GameVersions::preferred(
        $game,
        chain: TransferRegions::chainFor($game->console(), $regions),
    ));
}

it('sends the version in the first region of the order sorted for the send, and the console\'s when it has none', function () {
    $game = regionalGame(['Aerostar (Japan).sfc' => null, 'Aerostar (USA, Europe).sfc' => null]);

    expect(sentNames($game))->toBe(['Aerostar (USA, Europe).sfc'])
        ->and(sentNames($game, ['jp']))->toBe(['Aerostar (Japan).sfc'])
        // No Korean version: the order decides, as if nothing were asked.
        ->and(sentNames($game, ['kr']))->toBe(['Aerostar (USA, Europe).sfc']);
});

it('takes the console\'s own region order over the library\'s, and the library\'s over the artwork\'s', function () {
    $game = regionalGame(['Aerostar (Japan).sfc' => null, 'Aerostar (USA).sfc' => null, 'Aerostar (Europe).sfc' => null]);

    AppSetting::put(AppSetting::TRANSFER_REGIONS, ['us', 'jp']);
    expect(sentNames($game->fresh(['files'])))->toBe(['Aerostar (USA).sfc']);

    ConsoleOverrides::remember('snes', ['transfer_regions' => 'jp, eu'] + ConsoleOverrides::toForm('snes'));

    expect((new Console('snes'))->transferRegions)->toBe(['jp', 'eu'])
        ->and(sentNames($game->fresh(['files'])))->toBe(['Aerostar (Japan).sfc']);
});

it('counts the region recorded on a file, for a name that does not say', function () {
    $game = regionalGame(['Aerostar.sfc' => 'jp', 'Aerostar (USA).sfc' => null]);

    expect(sentNames($game, ['jp']))->toBe(['Aerostar.sfc'])
        ->and(GameVersions::regionsOf([$game->files->firstWhere('filename', 'Aerostar.sfc')]))->toBe(['jp']);
});

it('leaves out the artwork not chosen, file and tag alike', function () {
    $game = regionalGame(['smw.sfc' => null], 'Super Mario World');
    Media::factory()->for($game)->ofType('box-2D', 'us')->create(['path' => 'snes/smw/box-2D/x.png', 'extension' => 'png', 'size_bytes' => 10]);
    Media::factory()->for($game)->ofType('ss', 'us')->create(['path' => 'snes/smw/ss/x.png', 'extension' => 'png', 'size_bytes' => 10]);

    $target = app(BatoceraTarget::class)->withOptions(new TransferOptions(artwork: ['image']));
    $xml = simplexml_load_string($target->mergeGamelist(null, $game->fresh()));

    expect(array_column($target->plan($game->fresh())->toArray()['files'], 'destination'))->toBe(['roms/snes/smw.sfc', 'roms/snes/images/smw-image.png'])
        ->and((string) $xml->game->image)->toBe('./images/smw-image.png')
        ->and($xml->game->thumbnail->count())->toBe(0);

    // None at all is a choice too, and not the same as every one.
    $none = app(BatoceraTarget::class)->withOptions(new TransferOptions(artwork: []));
    expect(array_column($none->plan($game->fresh())->toArray()['files'], 'destination'))->toBe(['roms/snes/smw.sfc']);
});

it('reads the choices off the plan\'s URL for a drive, and drops what it does not know', function () {
    $game = regionalGame(['Aerostar (Japan).sfc' => null, 'Aerostar (USA, Europe).sfc' => null]);
    Media::factory()->for($game)->ofType('box-2D', 'us')->create(['path' => 'snes/a/box-2D/x.png', 'extension' => 'png', 'size_bytes' => 10]);

    $this->getJson(route('transfers.plan', ['target' => 'batocera', 'gameId' => $game->id, 'regions' => 'jp,us', 'artwork' => '']))
        ->assertOk()
        ->assertJsonPath('files', [['url' => route('transfers.files', ['file' => $game->files->firstWhere('filename', 'Aerostar (Japan).sfc')->id]), 'destination' => 'roms/snes/Aerostar (Japan).sfc', 'size' => 20]]);

    $this->getJson(route('transfers.plan', ['target' => 'batocera', 'gameId' => $game->id, 'regions' => 'mars', 'artwork' => 'thumbnail,nonsense']))
        ->assertOk()
        ->assertJsonPath('files.0.destination', 'roms/snes/Aerostar (USA, Europe).sfc')
        ->assertJsonPath('files.1.destination', 'roms/snes/images/Aerostar (USA, Europe)-thumb.png');

    $this->call('POST', route('transfers.gamelist', ['target' => 'batocera', 'gameId' => $game->id, 'regions' => 'jp']), [], [], [], ['CONTENT_TYPE' => 'application/xml'], '')
        ->assertOk()
        ->assertSee('<path>./Aerostar (Japan).sfc</path>', escape: false);
});

it('keeps the choices on the Transfer row, so a share is sent the same version and listed by it', function () {
    $game = regionalGame(['Aerostar (Japan).sfc' => null, 'Aerostar (USA, Europe).sfc' => null]);
    $destination = Destination::query()->create(['name' => 'RP4', 'host' => 'batocera', 'share' => 'share']);

    $target = app(BatoceraTarget::class)->withOptions(new TransferOptions(regions: ['jp'], artwork: []));
    app(SendToShare::class)->sendConsole('snes', $target, $destination);

    $share = $this->root.'/network/batocera/share/roms/snes';

    expect(Transfer::query()->sole()->options)->toBe(['regions' => ['jp'], 'artwork' => []])
        ->and(File::exists($share.'/Aerostar (Japan).sfc'))->toBeTrue()
        ->and(File::exists($share.'/Aerostar (USA, Europe).sfc'))->toBeFalse()
        ->and(File::get($share.'/gamelist.xml'))->toContain('<path>./Aerostar (Japan).sfc</path>');
});

it('takes the choices from the modal for a share', function () {
    $game = regionalGame(['Aerostar (Japan).sfc' => null, 'Aerostar (USA, Europe).sfc' => null]);
    $destination = Destination::query()->create(['name' => 'RP4', 'host' => 'batocera', 'share' => 'share']);

    Livewire::test('games.show', ['game' => $game])
        ->call('sendToShare', $destination->id, 'batocera', ['regions' => ['jp', 'mars', 'us'], 'artwork' => null]);

    // What the app does not know is dropped, not refused.
    expect(Transfer::query()->sole()->options)->toBe(['regions' => ['jp', 'us'], 'artwork' => null])
        ->and(File::exists($this->root.'/network/batocera/share/roms/snes/Aerostar (Japan).sfc'))->toBeTrue();
});

it('sends the dump most people play in the region, never one the provider flags as a translation', function () {
    $game = regionalGame([
        'Actraiser 2 (USA).sfc' => 'us',
        'Actraiser 2 (USA) (v1.0) (Evil Darkness Traducoes).sfc' => 'us',
        'Actraiser 2 (USA) (Rev 1).sfc' => 'us',
        'Actraiser 2 (Japan).sfc' => 'jp',
    ], 'Actraiser 2');

    $files = $game->files->keyBy('filename');
    $files['Actraiser 2 (USA).sfc']->update(['scrapes' => 5000]);
    $files['Actraiser 2 (USA) (Rev 1).sfc']->update(['scrapes' => 300]);
    $files['Actraiser 2 (USA) (v1.0) (Evil Darkness Traducoes).sfc']->update(['scrapes' => 90000, 'provider_flags' => ['trad']]);
    $files['Actraiser 2 (Japan).sfc']->update(['scrapes' => 999999]);

    AppSetting::put(AppSetting::TRANSFER_REGIONS, ['us', 'jp']);

    // Popularity counts within the region, and before the revision.
    expect(sentNames($game->fresh(['files'])))->toBe(['Actraiser 2 (USA).sfc'])
        ->and(sentNames($game->fresh(['files']), ['jp']))->toBe(['Actraiser 2 (Japan).sfc']);

    // A dump the provider knows over one it does not.
    $files['Actraiser 2 (USA).sfc']->update(['scrapes' => null]);
    expect(sentNames($game->fresh(['files'])))->toBe(['Actraiser 2 (USA) (Rev 1).sfc']);
});

it('replaces another version of the game on the drive, artwork and list entry too, and nothing it sends', function () {
    $game = regionalGame([
        'Minish Cap (USA).sfc' => 'us',
        'Minish Cap (USA) (PtBr) (v1.0).sfc' => 'us',
    ], 'Minish Cap');
    Media::factory()->for($game)->ofType('box-2D', 'us')->create(['path' => 'snes/mc/box-2D/x.png', 'extension' => 'png', 'size_bytes' => 10]);

    // Only the box goes this time; a mix sent before under the old name goes all the same.
    Media::factory()->for($game)->ofType('mixrbv2', 'us')->create(['path' => 'snes/mc/mixrbv2/x.png', 'extension' => 'png', 'size_bytes' => 10]);
    $target = app(BatoceraTarget::class)->withOptions(new TransferOptions(artwork: ['thumbnail']));

    expect($target->plan($game->fresh())->replaces)->toBe([
        'roms/snes/Minish Cap (USA) (PtBr) (v1.0).sfc',
        'roms/snes/images/Minish Cap (USA) (PtBr) (v1.0)-thumb.png',
        'roms/snes/images/Minish Cap (USA) (PtBr) (v1.0)-mix.png',
    ]);

    $existing = '<gameList>'
        .'<game><path>./Minish Cap (USA) (PtBr) (v1.0).sfc</path><name>Old</name></game>'
        .'<game><path>./Tetris.sfc</path><name>Tetris</name></game>'
        .'</gameList>';
    $xml = $target->mergeGamelist($existing, $game->fresh());

    expect($xml)->toContain('<path>./Minish Cap (USA).sfc</path>')
        ->not->toContain('PtBr')
        // Everybody else's entries are theirs.
        ->toContain('<path>./Tetris.sfc</path>');
});

it('removes the version a share held before once the new one has arrived', function () {
    $game = regionalGame(['Minish Cap (USA).sfc' => 'us', 'Minish Cap (Europe).sfc' => 'eu'], 'Minish Cap');
    $destination = Destination::query()->create(['name' => 'RP4', 'host' => 'batocera', 'share' => 'share']);
    AppSetting::put(AppSetting::TRANSFER_REGIONS, ['us', 'eu']);

    $share = $this->root.'/network/batocera/share/roms/snes';
    File::ensureDirectoryExists($share);
    File::put($share.'/Minish Cap (Europe).sfc', 'old');
    File::put($share.'/gamelist.xml', '<gameList><game><path>./Minish Cap (Europe).sfc</path><name>Minish Cap</name></game></gameList>');

    app(SendToShare::class)->send($game, app(BatoceraTarget::class), $destination);

    expect(File::exists($share.'/Minish Cap (USA).sfc'))->toBeTrue()
        ->and(File::exists($share.'/Minish Cap (Europe).sfc'))->toBeFalse()
        ->and(File::get($share.'/gamelist.xml'))->toContain('./Minish Cap (USA).sfc')->not->toContain('Europe');
});

it('reads a row written when a send could choose one region', function () {
    expect(TransferOptions::fromArray(['region' => 'jp', 'artwork' => null])->regions)->toBe(['jp'])
        ->and(TransferOptions::fromArray(['regions' => ['US', 'mars']])->regions)->toBe(['us'])
        ->and(TransferOptions::fromArray(['regions' => []])->regions)->toBeNull();
});
