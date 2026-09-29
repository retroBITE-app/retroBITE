<?php

use App\Enums\DocTemplate;
use App\Models\User;
use App\Services\DocLibrary;
use App\Support\DocPath;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/docs-feature-'.bin2hex(random_bytes(6));
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

test('docs page renders for an authenticated user', function () {
    $this->get(route('docs.index'))->assertOk();
});

test('a document can be created from each template', function (DocTemplate $template) {
    Livewire::test('docs.new-doc-modal')
        ->set('title', 'SCPH-39001 laser POT calibration')
        ->set('console', 'ps2')
        ->set('category', 'laser')
        ->set('template', $template->value)
        ->call('create')
        ->assertHasNoErrors()
        ->assertDispatched('doc-written');

    expect(Storage::disk('docs')->exists('ps2/scph-39001-laser-pot-calibration.md'))->toBeTrue();
})->with(DocTemplate::cases());

test('a document cannot be created for an unknown console', function () {
    Livewire::test('docs.new-doc-modal')
        ->set('title', 'Whatever')
        ->set('console', '../../etc')
        ->call('create')
        ->assertHasErrors('console');
});

test('the open document renders as html', function () {
    $doc = $this->library->create('ps2', 'Laser calibration', 'laser', [], "# Laser\n\n- A disc\n");

    $component = Livewire::test('docs.index', ['path' => $doc->path])
        ->assertSet('path', $doc->path);

    expect($component->instance()->html)->toContain('<li>A disc</li>');
});

test('the markdown tab shows the source without the front matter', function () {
    $doc = $this->library->create('ps2', 'Laser calibration', 'laser', [], "# Laser\n\nBody.\n");

    $body = Livewire::test('docs.index', ['path' => $doc->path])
        ->set('mode', 'markdown')
        ->instance()->current->body;

    expect($body)->toContain('# Laser')
        ->not->toContain('console: ps2');
});

test('editing a document writes a revision', function () {
    $doc = $this->library->create('ps2', 'Laser calibration', 'laser', [], "# Laser\n\nBody.\n");

    Livewire::test('docs.index', ['path' => $doc->path])
        ->call('edit')
        ->set('draft', "# Laser\n\nRewritten.\n")
        ->call('save')
        ->assertHasNoErrors();

    expect($this->library->revisions($doc->path))->toHaveCount(1)
        ->and($this->library->find($doc->path)->body)->toContain('Rewritten.');
});

test('search matches title, tag and body', function () {
    $this->library->create('ps2', 'Laser calibration', 'laser', ['slim'], "# Laser\n\nMeasured 1.60 kOhm.\n");
    $this->library->create('gc', 'Board differences', 'hardware', ['digital out'], "# Board\n\nNothing here.\n");

    $component = Livewire::test('docs.index');

    expect($component->set('query', 'kOhm')->get('docs')->pluck('console')->all())->toBe(['ps2'])
        ->and($component->set('query', 'slim')->get('docs')->pluck('console')->all())->toBe(['ps2'])
        ->and($component->set('query', 'Board diff')->get('docs')->pluck('console')->all())->toBe(['gc']);
});

test('the console and category chips filter the list', function () {
    $this->library->create('ps2', 'Laser calibration', 'laser', [], "# A\n");
    $this->library->create('gc', 'Board differences', 'hardware', [], "# B\n");

    $component = Livewire::test('docs.index');

    expect($component->set('filter', DocLibrary::consoleFilter('gc'))->get('docs')->pluck('console')->all())->toBe(['gc'])
        ->and($component->set('filter', DocLibrary::categoryFilter('laser'))->get('docs')->pluck('console')->all())->toBe(['ps2']);
});
