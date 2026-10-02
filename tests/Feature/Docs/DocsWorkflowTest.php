<?php

use App\Enums\DocTemplate;
use App\Models\ConsoleSourceFolder;
use App\Models\User;
use App\Services\DocLibrary;
use App\Services\GlobalSearch;
use App\Support\Console;
use App\Support\DocPath;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Docs beyond reading one: filed without a console, started as a
 * walkthrough, browsed back through its history, and found from Ctrl+K.
 */

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/docs-workflow-'.bin2hex(random_bytes(6));
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

test('a document can be created without a console, at the root', function () {
    Livewire::test('docs.new-doc-modal')
        ->set('title', 'Controller pinouts')
        ->call('create')
        ->assertHasNoErrors()
        ->assertDispatched('doc-written', path: 'controller-pinouts.md');

    $doc = app(DocLibrary::class)->find('controller-pinouts.md');

    expect(Storage::disk('docs')->exists('controller-pinouts.md'))->toBeTrue()
        ->and($doc?->console)->toBe('');
});

test('walkthrough is offered as a tag before any document uses it', function () {
    $categories = collect(Livewire::test('docs.new-doc-modal')->instance()->categories)->pluck('value');

    expect($categories)->toContain('walkthrough')
        ->and($this->library->categories())->not->toContain('walkthrough');
});

test('the walkthrough template files itself under walkthrough and writes its sections', function () {
    Livewire::test('docs.new-doc-modal')
        ->set('title', 'Paper Mario TTYD')
        ->set('console', 'gc')
        ->set('template', DocTemplate::Walkthrough->value)
        ->assertSet('category', 'walkthrough')
        ->call('create')
        ->assertHasNoErrors();

    $doc = app(DocLibrary::class)->find('gc/paper-mario-ttyd.md');

    expect($doc?->category)->toBe('walkthrough')
        ->and($doc?->body)->toContain('## Chapters')
        ->toContain('## Collectibles');
});

test('the console picker leads with the library and keeps the rest behind Show all', function () {
    expect(Livewire::test('docs.new-doc-modal')->html())->not->toContain(__('Show all consoles'));

    ConsoleSourceFolder::add(new Console('snes'));

    $html = Livewire::test('docs.new-doc-modal')->html();

    expect($html)->toContain(__('Show all consoles'))
        ->and($html)->toContain(__('No console'))
        ->and($html)->toMatch('/wire:key="console-snes"(?:(?!x-show).)*?>snes</s')
        ->and($html)->toMatch('/x-show="all"[^>]*wire:key="console-gc"/s');
});

test('history lists every earlier version and opens one read-only', function () {
    $doc = $this->library->create('ps2', 'Laser calibration', '', [], "# Laser\n\nFirst.\n");
    // Revisions are named by the millisecond; two saves in one would share a name.
    $this->travel(1)->seconds();
    $this->library->save($doc->path, "# Laser\n\nSecond.\n");
    $this->travel(1)->seconds();
    $this->library->save($doc->path, "# Laser\n\nThird.\n");

    $first = $this->library->revisions($doc->path)->last();

    $page = Livewire::test('docs.index', ['path' => $doc->path]);

    expect($page->instance()->history)->toHaveCount(2);

    $page->call('viewRevision', $first)
        ->assertSet('revision', $first)
        ->call('edit')
        ->assertSet('editing', false);

    expect($page->instance()->current->body)->toContain('First.')
        ->and($page->html())->toContain(__('Restore this version'));
});

test('a version is restored, and the one it replaced is kept', function () {
    $doc = $this->library->create('ps2', 'Laser calibration', '', [], "# Laser\n\nFirst.\n");
    $this->library->save($doc->path, "# Laser\n\nSecond.\n");

    $first = $this->library->revisions($doc->path)->first();

    Livewire::test('docs.index', ['path' => $doc->path])
        ->call('viewRevision', $first)
        ->call('restoreRevision')
        ->assertSet('revision', 0);

    $library = app(DocLibrary::class);

    expect($library->find($doc->path)->body)->toContain('First.')
        ->and($library->revisions($doc->path))->toHaveCount(2);
});

test('a revision that is not this document\'s is not opened', function () {
    $doc = $this->library->create('ps2', 'Laser calibration', '', [], "# Laser\n");

    Livewire::test('docs.index', ['path' => $doc->path])
        ->call('viewRevision', 12345)
        ->assertSet('revision', 0);
});

test('global search finds a doc by its title and its text', function () {
    $this->library->create('ps2', 'Laser calibration', '', [], "# Laser\n\nMeasured 1.60 kOhm.\n");

    $docs = function (string $term): array {
        $group = collect(app(GlobalSearch::class)->results($term))->firstWhere('key', 'docs');

        return $group === null ? [] : $group['items'];
    };

    expect(array_column($docs('laser'), 'label'))->toBe(['Laser calibration'])
        ->and(array_column($docs('kohm'), 'label'))->toBe(['Laser calibration'])
        ->and($docs('laser')[0]['url'])->toBe(route('docs.index', ['doc' => 'ps2/laser-calibration.md']))
        ->and($docs('l'))->toBe([]);
});
