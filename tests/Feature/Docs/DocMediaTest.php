<?php

use App\Models\User;
use App\Services\DocLibrary;
use App\Support\DocPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * A real 1x1 PNG. UploadedFile::fake()->image() needs GD, which neither the
 * php-fpm-alpine runtime nor this host has — and the `image` validation rule
 * reads the file's own bytes, so faking them is the more faithful test anyway.
 */
function fakePng(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    ));
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/docs-media-'.bin2hex(random_bytes(6));
    mkdir($this->root, 0o755, true);

    config(['settings.docs_path' => $this->root, 'filesystems.disks.docs.root' => $this->root]);
    Storage::forgetDisk('docs');

    app()->forgetInstance(DocPath::class);
    app()->instance(DocPath::class, new DocPath($this->root));

    $this->library = app(DocLibrary::class);
    $this->doc = $this->library->create('ps2', 'Laser calibration', 'laser', [], "# Laser\n\nBody.\n");

    $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->root));
});

test('an uploaded image lands in the media folder beside the doc', function () {
    Livewire::test('docs.media-modal', ['path' => $this->doc->path])
        ->set('upload', fakePng('SCPH 39001 pot.png'))
        ->set('caption', 'Pot location')
        ->call('insert')
        ->assertHasNoErrors()
        ->assertDispatched('doc-insert', snippet: '![Pot location](media/scph-39001-pot.png)');

    expect(Storage::disk('docs')->exists('ps2/media/scph-39001-pot.png'))->toBeTrue();
});

test('a non image upload is refused', function () {
    Livewire::test('docs.media-modal', ['path' => $this->doc->path])
        ->set('upload', UploadedFile::fake()->create('shell.php', 8, 'application/x-php'))
        ->call('insert')
        ->assertHasErrors('upload');
});

test('a youtube url inserts a plain text embed', function () {
    Livewire::test('docs.media-modal', ['path' => $this->doc->path])
        ->set('tab', 'youtube')
        ->set('url', 'https://youtu.be/ygdtkkCFxkE')
        ->call('insert')
        ->assertHasNoErrors()
        ->assertDispatched('doc-insert', snippet: '@video[https://youtu.be/ygdtkkCFxkE]');
});

test('a non youtube url is refused', function () {
    Livewire::test('docs.media-modal', ['path' => $this->doc->path])
        ->set('tab', 'youtube')
        ->set('url', 'https://vimeo.com/12345')
        ->call('insert')
        ->assertHasErrors('url');
});

test('the media route serves an attachment', function () {
    Storage::disk('docs')->put('ps2/media/pot.jpg', 'binary');

    $this->get(route('docs.media', ['path' => 'ps2/media/pot.jpg']))->assertOk();
});

test('the media route refuses a markdown file', function () {
    $this->get(route('docs.media', ['path' => $this->doc->path]))->assertNotFound();
});

test('the media route refuses a traversal', function () {
    $this->get(route('docs.media', ['path' => 'ps2/media/../../../../etc/passwd.jpg']))->assertNotFound();
});

test('guests cannot reach an attachment', function () {
    Storage::disk('docs')->put('ps2/media/pot.jpg', 'binary');

    auth()->logout();

    $this->get(route('docs.media', ['path' => 'ps2/media/pot.jpg']))->assertRedirect();
});

test('a document downloads as markdown', function () {
    $this->get(route('docs.download', ['path' => $this->doc->path]))
        ->assertOk()
        ->assertDownload('laser-calibration.md');
});

test('the download route refuses a traversal', function () {
    $this->get(route('docs.download', ['path' => '../../../etc/passwd.md']))->assertNotFound();
});

test('a document archives with the attachments it references', function () {
    if (! extension_loaded('zip')) {
        $this->markTestSkipped('ext-zip is not loaded; the php-fpm image installs it.');
    }

    Storage::disk('docs')->put('ps2/media/pot.jpg', 'binary');
    Storage::disk('docs')->put('ps2/media/unused.jpg', 'binary');
    $this->library->save($this->doc->path, "# Laser\n\n![Pot](media/pot.jpg)\n");

    $response = $this->get(route('docs.archive', ['path' => $this->doc->path]))->assertOk();

    $file = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($file, $response->streamedContent());

    $zip = new ZipArchive;
    $zip->open($file);

    $names = collect(range(0, $zip->numFiles - 1))->map(fn (int $i): string => (string) $zip->getNameIndex($i));

    expect($names)->toContain('laser-calibration.md')
        ->toContain('media/pot.jpg')
        ->not->toContain('media/unused.jpg');

    $zip->close();
    unlink($file);
});

test('appending a reference rewrites the draft rather than the file', function () {
    $component = Livewire::test('docs.reference-modal', [
        'path' => $this->doc->path,
        'body' => "# Laser\n\nBody.\n",
    ])
        ->set('title', 'Sony service manual')
        ->set('url', 'https://archive.org/details/ps2-service-manuals')
        ->set('note', 'Pinout on page 42')
        ->call('append')
        ->assertHasNoErrors();

    $body = Arr::get($component->effects, 'dispatches.0.params.body');

    expect($body)->toContain('## References')
        ->toContain('1. [Sony service manual](https://archive.org/details/ps2-service-manuals) — Pinout on page 42');
});

test('a reference is numbered after the ones already listed', function () {
    $existing = "# Laser\n\n## References\n\n1. [One](https://example.com/a)\n";

    $component = Livewire::test('docs.reference-modal', ['path' => $this->doc->path, 'body' => $existing])
        ->set('title', 'Two')
        ->set('url', 'https://example.com/b')
        ->call('append');

    expect(Arr::get($component->effects, 'dispatches.0.params.body'))
        ->toContain('2. [Two](https://example.com/b)');
});

test('a reference needs a valid url', function () {
    Livewire::test('docs.reference-modal', ['path' => $this->doc->path, 'body' => '# A'])
        ->set('title', 'Bad')
        ->set('url', 'javascript:alert(1)')
        ->call('append')
        ->assertHasErrors('url');
});

test('an imported file is filed from its own front matter', function () {
    $markdown = "---\ntitle: RCP heatsink swap\nconsole: n64\ncategory: repair\ntags: [thermal]\n---\n\n# RCP heatsink swap\n\nBody.\n";

    Livewire::test('docs.import-modal')
        ->set('upload', UploadedFile::fake()->createWithContent('notes.md', $markdown))
        ->assertSet('title', 'RCP heatsink swap')
        ->assertSet('console', 'n64')
        ->assertSet('category', 'repair')
        ->call('import')
        ->assertHasNoErrors()
        ->assertDispatched('doc-written');

    $imported = $this->library->find('n64/rcp-heatsink-swap.md');

    expect($imported)->not->toBeNull()
        ->and($imported->tags)->toBe(['thermal'])
        ->and($imported->body)->toContain('Body.');
});

test('an imported file with no front matter takes its title from the heading', function () {
    Livewire::test('docs.import-modal')
        ->set('upload', UploadedFile::fake()->createWithContent('notes.md', "# Drive chip matrix\n\nBody.\n"))
        ->assertSet('title', 'Drive chip matrix')
        ->assertSet('console', '');
});
