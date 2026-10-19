<?php

use App\Conversion\Tools;
use App\Enums\ConversionStatus;
use App\Enums\DecryptState;
use App\Jobs\InspectGameFile;
use App\Jobs\RunConversion;
use App\Models\ConsoleSourceFolder;
use App\Models\Conversion;
use App\Models\GameFile;
use App\Models\User;
use App\Support\Console;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Fakes\Ps3Image;

/**
 * Tools → Decrypt and the PS3 game page: where each image stands, adding its
 * key, and queueing the decryption.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-decrypt-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/ps3');
    config()->set('settings.games_path', $this->root);
    config()->set('decrypters.tools.ps3dec.path', '/bin/true');

    $this->actingAs(User::factory()->create());
    Queue::fake();
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

/** One PS3 image on disk and on record, in the state asked for. */
function ps3Image(string $title, DecryptState $state): GameFile
{
    ConsoleSourceFolder::add(new Console('ps3'));

    $file = Ps3Image::fileRow(
        test()->root,
        $title.' (USA).iso',
        match ($state) {
            DecryptState::Unchecked, DecryptState::Unknown => null,
            DecryptState::Decrypted => false,
            default => true,
        },
        $state === DecryptState::Ready,
        game: ['slug' => Str::slug($title)],
        meta: $state === DecryptState::Unknown ? ['license_id' => 'BLUS30538'] : [],
    );

    // A key file that is a key, just not this disc's.
    if ($state === DecryptState::WrongKey) {
        File::put(test()->root.'/ps3/'.$title.' (USA).dkey', str_repeat('0', 32)."\n");
    }

    return $file;
}

it('sits under Tools in the menu, and says so when no console it decrypts is in the library', function () {
    ConsoleSourceFolder::add(new Console('ps2'));

    $this->get(route('tools.decrypt'))
        ->assertOk()
        ->assertSee(route('tools.decrypt'), false)
        ->assertSee('No console with encrypted discs is in the library yet.');
});

it('has a tab for each console in the library something on the page decrypts, and only those', function () {
    ConsoleSourceFolder::add(new Console('ps2'));
    ps3Image('Keyed', DecryptState::Ready);

    Livewire::test('tools.decrypt')
        ->assertSet('consoleKey', 'ps3')
        ->assertSee('PlayStation 3')
        ->assertDontSee('PlayStation 2')
        ->call('selectConsole', 'ps2')
        ->assertSet('consoleKey', 'ps3');
});

it('lists every PS3 image with where it stands, and filters by it', function () {
    ps3Image('Locked', DecryptState::NeedsKey);
    ps3Image('Keyed', DecryptState::Ready);
    ps3Image('Done', DecryptState::Decrypted);
    ps3Image('Fresh', DecryptState::Unchecked);

    Livewire::test('tools.decrypt')
        ->assertSee(['Locked', 'Keyed', 'Done', 'Fresh'])
        ->assertSee(['Needs key', 'Key ready', 'Decrypted', 'Not checked'])
        ->call('filter', 'needs_key')
        ->assertSee('Locked')
        ->assertDontSee('Keyed (USA).iso')
        ->call('filter', 'nonsense')
        ->assertSet('show', '')
        ->set('search', 'kEyEd')
        ->assertSee('Keyed (USA).iso')
        ->assertDontSee('Locked (USA).iso');
});

it('reads an unread image on the spot', function () {
    $file = ps3Image('Fresh', DecryptState::Unchecked);

    Livewire::test('tools.decrypt')
        ->call('check', $file->id)
        ->assertSee('Needs key');

    expect($file->fresh()?->meta?->encrypted)->toBeTrue()
        ->and($file->fresh()?->meta?->license_id)->toBe('BLUS30538');
});

it('picks images in any state, and clears the picks once the confirm has queued them', function () {
    $ready = ps3Image('Keyed', DecryptState::Ready);
    $locked = ps3Image('Locked', DecryptState::NeedsKey);

    Livewire::test('tools.decrypt')
        ->call('toggle', $locked->id)
        ->assertSet('picked', [$locked->id])
        ->call('toggleAll')
        ->assertSet('picked', [$ready->id, $locked->id])
        ->call('toggleAll')
        ->assertSet('picked', [])
        ->call('toggleAll')
        ->dispatch('decrypt-queued')
        ->assertSet('picked', []);
});

it('asks to decrypt only the picks whose key fits', function () {
    $ready = ps3Image('Keyed', DecryptState::Ready);
    $locked = ps3Image('Locked', DecryptState::NeedsKey);
    $wrong = ps3Image('Wrong', DecryptState::WrongKey);

    Livewire::test('tools.decrypt')
        ->call('toggleAll')
        ->assertSee('Decrypt 1')
        ->assertSee('Check 3')
        ->call('decryptPicked')
        ->assertDispatched('decrypt-confirm', fileIds: [$ready->id])
        ->assertSet('picked', [$ready->id, $locked->id, $wrong->id]);
});

it('queues one check per picked image, and one per image not settled for Check all', function () {
    $ready = ps3Image('Keyed', DecryptState::Ready);
    $fresh = ps3Image('Fresh', DecryptState::Unchecked);
    $unknown = ps3Image('Odd', DecryptState::Unknown);
    $wrong = ps3Image('Wrong', DecryptState::WrongKey);
    ps3Image('Done', DecryptState::Decrypted);

    Livewire::test('tools.decrypt')
        ->call('toggle', $ready->id)
        ->call('checkPicked')
        ->assertDispatched('toast-show')
        ->assertSet('picked', []);

    Queue::assertPushed(InspectGameFile::class, function (InspectGameFile $job) use ($ready): bool {
        return $job->fileId === $ready->id && $job->force;
    });

    Livewire::test('tools.decrypt')->call('checkAll');

    $checked = collect(Queue::pushed(InspectGameFile::class))->map(function (InspectGameFile $job): int {
        return $job->fileId;
    })->all();

    expect($checked)->toEqualCanonicalizing([$ready->id, $fresh->id, $unknown->id, $wrong->id]);
});

it('says what a check found, and that a disc could not be read when it could not', function () {
    $file = ps3Image('Fresh', DecryptState::Unchecked);

    Livewire::test('tools.decrypt')
        ->call('check', $file->id)
        ->assertDispatched('toast-show', function (string $name, array $params): bool {
            return Arr::get($params, 'dataset.variant') === 'warning';
        });

    File::put($this->root.'/ps3/Fresh (USA).iso', random_bytes(64 * 2048));
    $file->rememberMeta(['license_id' => null, 'encrypted' => null]);

    Livewire::test('tools.decrypt')
        ->call('check', $file->id)
        ->assertDispatched('toast-show', function (string $name, array $params): bool {
            return Arr::get($params, 'dataset.variant') === 'danger'
                && Arr::get($params, 'slots.text') === 'Could not read this image as a PS3 disc.';
        });
});

it('shows a key beside the image that does not fit as Wrong key, and offers to replace it', function () {
    $file = ps3Image('Wrong', DecryptState::WrongKey);

    Livewire::test('tools.decrypt')
        ->assertSee('Wrong key')
        ->assertSee('Replace key');

    Livewire::test('games.show', ['game' => $file->game])
        ->assertSee('Wrong key')
        ->assertSee('Replace key');
});

it('says a disc read without an answer is Unknown, not Not checked', function () {
    ps3Image('Odd', DecryptState::Unknown);

    Livewire::test('tools.decrypt')
        ->assertSee('Unknown')
        ->assertSee('Check');
});

it('asks before decrypting, says the image is replaced and its key file deleted, and queues once', function () {
    $ready = ps3Image('Keyed', DecryptState::Ready);
    $locked = ps3Image('Locked', DecryptState::NeedsKey);

    Livewire::test('decrypt.confirm-modal')
        ->call('open', [$ready->id, $locked->id])
        ->assertSee(['Keyed (USA).iso', 'Locked (USA).iso'])
        ->assertSee('replaces the encrypted one')
        ->assertSee('The .dkey beside it is deleted too')
        ->assertSee('This cannot be undone.')
        ->call('decrypt')
        ->assertDispatched('conversion-queued')
        ->assertDispatched('decrypt-queued')
        ->assertSet('fileIds', []);

    // On record before decrypting deletes the .dkey.
    expect($ready->fresh()?->meta?->disc_key)->toBe(Ps3Image::KEY);

    expect(Conversion::query()->pluck('converter', 'game_file_id')->all())->toBe([$ready->id => 'ps3-decrypt']);
    Queue::assertPushed(RunConversion::class);
});

it('shows only decryption in the queue under the list', function () {
    $file = ps3Image('Keyed', DecryptState::Ready);
    $row = ['sources' => [], 'options' => [], 'status' => ConversionStatus::Queued, 'queued_at' => now()];
    Conversion::query()->create([...$row, 'console' => 'ps3', 'converter' => 'ps3-decrypt', 'label' => 'Keyed (USA).iso', 'game_file_id' => $file->id]);
    Conversion::query()->create([...$row, 'console' => 'ps2', 'converter' => 'chd-dvd', 'label' => 'Other.iso']);

    Livewire::test('conversion.queue', ['converter' => 'ps3-decrypt'])
        ->assertSee('Keyed (USA).iso')
        ->assertDontSee('Other.iso');
});

it('refuses a key that is not one, and one that does not fit the disc', function () {
    $file = ps3Image('Locked', DecryptState::NeedsKey);

    Livewire::test('decrypt.key-modal')
        ->call('open', $file->id)
        ->set('key', 'not a key')
        ->call('save')
        ->assertHasErrors('key')
        ->set('key', str_repeat('0', 32))
        ->call('save')
        ->assertHasErrors('key')
        ->assertNotDispatched('disc-key-saved');

    expect(File::exists($this->root.'/ps3/Locked (USA).dkey'))->toBeFalse();
});

it('keeps a key that fits beside the image, where ps3netsrv finds it', function () {
    $file = ps3Image('Locked', DecryptState::NeedsKey);

    Livewire::test('decrypt.key-modal')
        ->call('open', $file->id)
        ->set('key', ' '.Str::lower(Ps3Image::KEY).' ')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('disc-key-saved', fileId: $file->id);

    expect(File::get($this->root.'/ps3/Locked (USA).dkey'))->toBe(Ps3Image::KEY."\n");
});

it('shows a PS3 image\'s state on its game page, and decrypts it from there', function () {
    $file = ps3Image('Keyed', DecryptState::Ready);

    Livewire::test('games.show', ['game' => $file->game])
        ->assertSee('Key ready')
        ->assertSee('Change key')
        ->assertSeeHtml("decrypt-confirm', { fileIds: [{$file->id}] }");

    Livewire::test('decrypt.confirm-modal')->call('open', [$file->id])->call('decrypt');

    expect(Conversion::query()->where('converter', 'ps3-decrypt')->count())->toBe(1);
});

it('never queues the same image twice, however often Decrypt is pressed', function () {
    $file = ps3Image('Keyed', DecryptState::Ready);
    $modal = Livewire::test('decrypt.confirm-modal');

    $modal->call('open', [$file->id])->call('decrypt');
    $modal->call('open', [$file->id])->call('decrypt');

    expect(Conversion::query()->where('game_file_id', $file->id)->count())->toBe(1);
});

it('shows a disc key on the game page, still there once the .dkey has gone', function () {
    $file = ps3Image('Done', DecryptState::Decrypted);
    $file->rememberMeta(['disc_key' => Ps3Image::KEY]);

    Livewire::test('games.show', ['game' => $file->game])
        ->assertSee('Disc key')
        ->assertSee(Ps3Image::KEY);
});

it('lists its own tools under Tool paths, and only those', function () {
    ps3Image('Keyed', DecryptState::Ready);

    $this->get(route('tools.decrypt'))->assertSee('Tool paths');

    Livewire::test('conversion.tool-paths', ['only' => Tools::onPage('decrypt')])
        ->assertSee('ps3dec')
        ->assertDontSee('chdman');

    // And Conversion's lists every tool but that one, those no converter uses yet included.
    expect(Tools::onPage('decrypt'))->toBe(['ps3dec'])
        ->and(Tools::onPage(null))->not->toContain('ps3dec')
        ->and(Tools::onPage(null))->toContain('chdman', 'extract-xiso');
});

it('shows an image with a decryption under way as Decrypting, with nothing left to press', function () {
    $file = ps3Image('Keyed', DecryptState::Ready);
    Livewire::test('decrypt.confirm-modal')->call('open', [$file->id])->call('decrypt');

    Livewire::test('tools.decrypt')
        ->assertSee('Decrypting')
        ->assertDontSee('Change key')
        ->call('toggle', $file->id)
        ->assertSet('picked', []);

    Livewire::test('games.show', ['game' => $file->game])
        ->assertSee('Decrypting')
        ->assertDontSeeHtml("decrypt-confirm', { fileIds: [{$file->id}] }");
});

it('says when an image is already being decrypted, and offers nothing to press when all are', function () {
    $file = ps3Image('Keyed', DecryptState::Ready);
    Livewire::test('decrypt.confirm-modal')->call('open', [$file->id])->call('decrypt');

    Livewire::test('decrypt.confirm-modal')
        ->call('open', [$file->id])
        ->assertSee('One of these is already being decrypted')
        ->assertSet('decrypting', [$file->id]);
});
