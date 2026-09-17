<?php

use App\Models\User;
use App\Services\DocArchive;
use App\Services\DocLibrary;
use App\Support\DocPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/docs-import-'.bin2hex(random_bytes(6));
    mkdir($this->root, 0o755, true);

    config(['settings.docs_path' => $this->root, 'filesystems.disks.docs.root' => $this->root]);
    Storage::forgetDisk('docs');

    app()->forgetInstance(DocPath::class);
    app()->instance(DocPath::class, new DocPath($this->root));

    $this->library = app(DocLibrary::class);
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]));

    // Last, so the temp root is still set for afterEach to clean up.
    if (! extension_loaded('zip')) {
        $this->markTestSkipped('ext-zip is not loaded; the php-fpm image installs it.');
    }
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->root));
});

/**
 * @param  array<string, string>  $entries
 */
function zipUpload(array $entries, string $name = 'bundle.zip'): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'zip');

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);

    foreach ($entries as $entry => $contents) {
        $zip->addFromString($entry, $contents);
    }

    $zip->close();

    // Livewire's testing helper reads a dynamic `name` property that only
    // UploadedFile::fake() sets, so the bytes are handed to a fake file.
    $upload = UploadedFile::fake()->createWithContent($name, (string) file_get_contents($path));

    unlink($path);

    return $upload;
}

function samplePng(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    );
}

test('a zip imports its document and its images', function () {
    $markdown = "---\ntitle: Laser tuning\ncategory: repair\ntags: [laser]\n---\n\n# Laser tuning\n\n![Pot](media/pot.png)\n";

    Livewire::test('docs.import-modal')
        ->set('upload', zipUpload([
            'laser-tuning.md' => $markdown,
            'media/pot.png' => samplePng(),
        ]))
        ->assertSet('title', 'Laser tuning')
        ->assertSet('attachments', 1)
        ->set('console', 'gc')
        ->call('import')
        ->assertHasNoErrors()
        ->assertDispatched('doc-written');

    expect(Storage::disk('docs')->exists('gc/laser-tuning.md'))->toBeTrue()
        ->and(Storage::disk('docs')->exists('gc/media/pot.png'))->toBeTrue();
});

test('the chosen console decides the folder, not the front matter', function () {
    $markdown = "---\ntitle: Laser tuning\nconsole: ps2\n---\n\n# Laser tuning\n";

    Livewire::test('docs.import-modal')
        ->set('upload', zipUpload(['laser-tuning.md' => $markdown]))
        ->set('console', 'n64')
        ->call('import');

    expect(Storage::disk('docs')->exists('n64/laser-tuning.md'))->toBeTrue()
        ->and(Storage::disk('docs')->exists('ps2/laser-tuning.md'))->toBeFalse();
});

test('an imported image never overwrites one already in the console folder', function () {
    Storage::disk('docs')->put('gc/media/pot.png', 'the original');

    Livewire::test('docs.import-modal')
        ->set('upload', zipUpload([
            'laser.md' => "# Laser\n\n![Pot](media/pot.png)\n",
            'media/pot.png' => samplePng(),
        ]))
        ->set('console', 'gc')
        ->call('import')
        ->assertHasNoErrors();

    expect(Storage::disk('docs')->get('gc/media/pot.png'))->toBe('the original')
        ->and(Storage::disk('docs')->exists('gc/media/pot-2.png'))->toBeTrue()
        ->and($this->library->find('gc/laser.md')->body)->toContain('media/pot-2.png');
});

test('a zip slip entry is never written', function () {
    Livewire::test('docs.import-modal')
        ->set('upload', zipUpload([
            'laser.md' => "# Laser\n",
            '../../../../tmp/evil.png' => samplePng(),
            'media/../../evil2.png' => samplePng(),
        ]))
        ->assertSet('attachments', 0)
        ->set('console', 'gc')
        ->call('import')
        ->assertHasNoErrors();

    expect(Storage::disk('docs')->allFiles())->toBe(['gc/laser.md'])
        ->and(file_exists('/tmp/evil.png'))->toBeFalse();
});

test('an archive with no document is refused', function () {
    Livewire::test('docs.import-modal')
        ->set('upload', zipUpload(['media/pot.png' => samplePng()]))
        ->assertHasErrors('upload');
});

test('an archive with two documents is refused', function () {
    Livewire::test('docs.import-modal')
        ->set('upload', zipUpload(['a.md' => '# A', 'b.md' => '# B']))
        ->assertHasErrors('upload');
});

test('desktop zip noise is ignored', function () {
    Livewire::test('docs.import-modal')
        ->set('upload', zipUpload([
            'laser.md' => "# Laser\n",
            '__MACOSX/._laser.md' => 'junk',
            '.DS_Store' => 'junk',
            'media/.DS_Store' => 'junk',
        ]))
        ->assertHasNoErrors('upload')
        ->assertSet('attachments', 0)
        ->assertSet('title', 'Laser');
});

test('a plain markdown file still imports', function () {
    Livewire::test('docs.import-modal')
        ->set('upload', UploadedFile::fake()->createWithContent('notes.md', "# Drive chip matrix\n\nBody.\n"))
        ->assertSet('title', 'Drive chip matrix')
        ->assertSet('attachments', 0)
        ->set('console', 'wii')
        ->call('import')
        ->assertHasNoErrors();

    expect(Storage::disk('docs')->exists('wii/drive-chip-matrix.md'))->toBeTrue();
});

test('a round trip through export and import keeps the images', function () {
    $doc = $this->library->create('ps2', 'Laser calibration', 'laser', [], "# Laser\n\n![Pot](media/pot.png)\n");
    Storage::disk('docs')->put('ps2/media/pot.png', samplePng());

    $archive = app(DocArchive::class)->write($this->library->find($doc->path));

    Livewire::test('docs.import-modal')
        ->set('upload', UploadedFile::fake()->createWithContent(
            'laser-calibration.zip',
            (string) file_get_contents($archive),
        ))
        ->assertSet('attachments', 1)
        ->set('console', 'gc')
        ->call('import')
        ->assertHasNoErrors();

    expect(Storage::disk('docs')->exists('gc/laser-calibration.md'))->toBeTrue()
        ->and(Storage::disk('docs')->exists('gc/media/pot.png'))->toBeTrue();
});
