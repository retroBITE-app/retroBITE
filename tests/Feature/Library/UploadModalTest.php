<?php

use App\Enums\UploadRejection;
use App\Jobs\ScanConsoleFolder;
use App\Models\AppSetting;
use App\Models\ConsoleSourceFolder;
use App\Models\User;
use App\Services\RomUploads;
use App\Support\Console;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The shelf's half of an upload: whether it is offered at all, what the modal
 * hands the browser, and that a batch queues nothing. The byte-level rules
 * are UploadTest's.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-upload-modal-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/ps2');
    File::ensureDirectoryExists($this->root.'/snes');
    config()->set('settings.games_path', $this->root);

    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('offers uploads on the shelf only while the setting is on', function () {
    ConsoleSourceFolder::add(new Console('snes'));

    expect(Livewire::test('games.index', ['console' => 'snes'])->instance()->canUpload)->toBeFalse();

    AppSetting::put(AppSetting::UI_UPLOADS, true);

    expect(Livewire::test('games.index', ['console' => 'snes'])->instance()->canUpload)->toBeTrue();
});

it('offers no uploads for a console that is not in the library', function () {
    AppSetting::put(AppSetting::UI_UPLOADS, true);

    expect(Livewire::test('games.index', ['console' => 'snes'])->instance()->canUpload)->toBeFalse();
});

it('keeps working with the setting off, since the setting only hides it', function () {
    ConsoleSourceFolder::add(new Console('snes'));

    expect(AppSetting::enabled(AppSetting::UI_UPLOADS))->toBeFalse();

    $answer = Livewire::test('games.upload-modal', ['console' => 'snes'])->instance()
        ->begin('Super Mario World (USA).sfc', 10, '', app(RomUploads::class));

    expect($answer)->toMatchArray(['ok' => true, 'chunk' => RomUploads::CHUNK_BYTES])
        ->and($answer['url'])->toBe(route('uploads.chunk', ['upload' => $answer['id']]));
});

it('asks for a destination only where the layout offers more than one', function () {
    ConsoleSourceFolder::add(new Console('ps2'), null, 'opl');
    ConsoleSourceFolder::add(new Console('snes'));

    expect(Livewire::test('games.upload-modal', ['console' => 'ps2'])->instance()->destinations)
        ->toEqualCanonicalizing(['DVD' => 'ps2/DVD/', 'CD' => 'ps2/CD/']);

    expect(Livewire::test('games.upload-modal', ['console' => 'snes'])->instance()->destinations)
        ->toBe(['' => 'snes/']);
});

it('hands the browser the console\'s own file types', function () {
    ConsoleSourceFolder::add(new Console('snes'));

    $extensions = Livewire::test('games.upload-modal', ['console' => 'snes'])->instance()->extensions;

    expect($extensions)->toContain('sfc', 'smc')->not->toContain('iso');
});

it('answers a refusal with its fixed message', function () {
    ConsoleSourceFolder::add(new Console('snes'));

    $answer = Livewire::test('games.upload-modal', ['console' => 'snes'])->instance()
        ->begin('notes.txt', 10, '', app(RomUploads::class));

    expect($answer)->toBe(['ok' => false, 'message' => UploadRejection::WrongType->label()]);
});

it('queues no scan when a batch is done', function () {
    Bus::fake();
    ConsoleSourceFolder::add(new Console('snes'));

    Livewire::test('games.upload-modal', ['console' => 'snes'])->call('uploaded', 3);

    // A file count, yes — the folder has new files — but never a scan.
    Bus::assertNotDispatched(ScanConsoleFolder::class);
});

it('files uploads into game folders where the layout wants them', function () {
    File::ensureDirectoryExists($this->root.'/psx');
    ConsoleSourceFolder::add(new Console('psx'), null, 'folders');

    $modal = Livewire::test('games.upload-modal', ['console' => 'psx']);

    // The list groups by folder only where there are folders to group by.
    expect($modal->instance()->perGameFolders)->toBeTrue();

    $answer = $modal->instance()->begin('Crash.cue', 4, '', app(RomUploads::class), 'Crash Bandicoot');

    expect($answer['ok'])->toBeTrue();

    expect(Livewire::test('games.upload-modal', ['console' => 'ps2'])->instance()->perGameFolders)->toBeFalse();
});
