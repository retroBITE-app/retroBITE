<?php

use App\Models\User;
use App\Services\DocLibrary;
use App\Support\DocPath;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/docs-delete-'.bin2hex(random_bytes(6));
    mkdir($this->root, 0o755, true);

    config(['settings.docs_path' => $this->root, 'filesystems.disks.docs.root' => $this->root]);
    Storage::forgetDisk('docs');

    app()->forgetInstance(DocPath::class);
    app()->instance(DocPath::class, new DocPath($this->root));

    $this->library = app(DocLibrary::class);
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->root));
});

test('deleting a document removes the attachments only it used', function () {
    $doc = $this->library->create('gc', 'Laser', 'repair', [], "# Laser\n\n![Pot](media/pot.png)\n");
    Storage::disk('docs')->put('gc/media/pot.png', 'bytes');

    $this->library->delete($doc->path);

    expect(Storage::disk('docs')->exists('gc/media/pot.png'))->toBeFalse()
        ->and(Storage::disk('docs')->exists($doc->path))->toBeFalse();
});

test('an attachment another document still links is kept', function () {
    $doomed = $this->library->create('gc', 'Laser', 'repair', [], "# Laser\n\n![Pot](media/pot.png)\n");
    $this->library->create('gc', 'Drive', 'repair', [], "# Drive\n\n![Pot](media/pot.png)\n");
    Storage::disk('docs')->put('gc/media/pot.png', 'bytes');

    $this->library->delete($doomed->path);

    expect(Storage::disk('docs')->exists('gc/media/pot.png'))->toBeTrue();
});

test('deleting takes only the orphans when a document has several images', function () {
    $doomed = $this->library->create('gc', 'Laser', 'repair', [], "# Laser\n\n![A](media/a.png)\n![B](media/b.png)\n");
    $this->library->create('gc', 'Drive', 'repair', [], "# Drive\n\n![B](media/b.png)\n");
    Storage::disk('docs')->put('gc/media/a.png', 'bytes');
    Storage::disk('docs')->put('gc/media/b.png', 'bytes');

    $this->library->delete($doomed->path);

    expect(Storage::disk('docs')->exists('gc/media/a.png'))->toBeFalse()
        ->and(Storage::disk('docs')->exists('gc/media/b.png'))->toBeTrue();
});

test('an identically named attachment in another console is untouched', function () {
    $doc = $this->library->create('gc', 'Laser', 'repair', [], "# Laser\n\n![Pot](media/pot.png)\n");
    Storage::disk('docs')->put('gc/media/pot.png', 'gc bytes');
    Storage::disk('docs')->put('ps2/media/pot.png', 'ps2 bytes');

    $this->library->delete($doc->path);

    expect(Storage::disk('docs')->exists('gc/media/pot.png'))->toBeFalse()
        ->and(Storage::disk('docs')->get('ps2/media/pot.png'))->toBe('ps2 bytes');
});

test('deleting also removes the revision history', function () {
    $doc = $this->library->create('gc', 'Laser', 'repair', [], "# Laser\n");
    $this->library->save($doc->path, "# Laser\n\nChanged.\n");

    expect($this->library->revisions($doc->path))->toHaveCount(1);

    $this->library->delete($doc->path);

    expect(Storage::disk('docs')->exists(DocPath::REVISIONS_DIR.'/gc/laser'))->toBeFalse();
});

test('deleting through the page clears the open document', function () {
    $doc = $this->library->create('gc', 'Laser', 'repair', [], "# Laser\n\n![Pot](media/pot.png)\n");
    Storage::disk('docs')->put('gc/media/pot.png', 'bytes');

    Livewire::test('docs.index', ['path' => $doc->path])
        ->call('delete')
        ->assertSet('path', '');

    expect(Storage::disk('docs')->exists('gc/media/pot.png'))->toBeFalse();
});
