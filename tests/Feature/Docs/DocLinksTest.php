<?php

use App\Models\DocLink;
use App\Models\Game;
use App\Models\User;
use App\Services\DocLibrary;
use App\Services\DocLinks;
use App\Support\DocPath;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Docs linked to games: kept in step with the files and the games, shown under
 * a game's Docs tab, and managed from both pages.
 */

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/doc-links-'.bin2hex(random_bytes(6));
    mkdir($this->root, 0o755, true);

    config(['settings.docs_path' => $this->root, 'filesystems.disks.docs.root' => $this->root]);
    Storage::forgetDisk('docs');

    app()->forgetInstance(DocPath::class);
    app()->instance(DocPath::class, new DocPath($this->root));

    $this->library = app(DocLibrary::class);
    $this->links = app(DocLinks::class);
    $this->game = Game::factory()->forConsole('gc')->create(['title' => 'Paper Mario', 'slug' => 'paper-mario']);

    $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->root));
});

test('a doc is linked once however often it is linked, and unlinked', function () {
    $doc = $this->library->create('gc', 'Walkthrough', 'walkthrough', [], "# Walkthrough\n");

    $this->links->link($doc->path, $this->game);
    $this->links->link($doc->path, $this->game);

    expect($this->links->docsFor($this->game)->pluck('path')->all())->toBe([$doc->path])
        ->and($this->links->gamesFor($doc->path)->pluck('id')->all())->toBe([$this->game->id]);

    $this->links->unlink($doc->path, $this->game);

    expect($this->links->docsFor($this->game))->toBeEmpty();
});

test('a doc that is not there cannot be linked', function () {
    $this->links->link('gc/nothing.md', $this->game);
})->throws(InvalidArgumentException::class);

test('a link whose file was removed on the disk is dropped once the index has been checked', function () {
    $doc = $this->library->create('gc', 'Walkthrough', '', [], "# W\n");
    $this->links->link($doc->path, $this->game);

    Storage::disk('docs')->delete($doc->path);

    // A page reads the index without looking at the disk, so the game page
    // still shows the link until the documents page or MeasureLibrary checks.
    expect(app(DocLinks::class)->docsFor($this->game))->toHaveCount(1);

    app(DocLibrary::class)->verify();

    expect(app(DocLinks::class)->docsFor($this->game))->toBeEmpty()
        ->and(DocLink::query()->count())->toBe(0);
});

test('deleting a doc removes its links, and deleting a game removes its', function () {
    $kept = $this->library->create('gc', 'Kept', '', [], "# K\n");
    $gone = $this->library->create('gc', 'Gone', '', [], "# G\n");
    $other = Game::factory()->forConsole('gc')->create();

    $this->links->link($kept->path, $this->game);
    $this->links->link($gone->path, $this->game);
    $this->links->link($kept->path, $other);

    $this->library->delete($gone->path);
    expect(DocLink::query()->where('doc_path', $gone->path)->count())->toBe(0);

    $other->delete();
    expect(DocLink::query()->pluck('game_id')->all())->toBe([$this->game->id]);
});

test('a game with no linked docs has no Docs tab', function () {
    $tabs = array_column(Livewire::test('games.show', ['game' => $this->game])->instance()->contentTabs, 'key');

    expect($tabs)->not->toContain('docs');
});

test('a linked doc gets a Docs tab that reads it and opens it on the Documents page', function () {
    $doc = $this->library->create('gc', 'Chapter guide', 'walkthrough', [], "# Chapter guide\n\nTalk to Goombella first.\n");
    $this->links->link($doc->path, $this->game);

    $page = Livewire::test('games.show', ['game' => $this->game])->call('selectTab', 'docs');

    $tab = collect($page->instance()->contentTabs)->firstWhere('key', 'docs');

    expect($tab['count'])->toBe(1)
        ->and($page->instance()->openDocHtml)->toContain('Talk to Goombella first.');

    // Escaped as the page prints them: the & of the edit link is &amp; there.
    $page->assertSee(route('docs.index', ['doc' => $doc->path]))
        ->assertDontSee(route('docs.index', ['doc' => $doc->path, 'edit' => 1]));
});

test('a doc written about a game from its page is linked and opened in the editor', function () {
    Livewire::test('docs.new-doc-modal', ['gameId' => $this->game->id, 'trigger' => false])
        ->assertSet('title', 'Paper Mario')
        ->assertSet('console', 'gc')
        ->call('create')
        ->assertRedirect(route('docs.index', ['doc' => 'gc/paper-mario.md', 'edit' => 1]));

    expect(app(DocLinks::class)->docsFor($this->game)->pluck('path')->all())->toBe(['gc/paper-mario.md']);
});

test('a doc already written is linked from the game page\'s dialog', function () {
    $doc = $this->library->create('gc', 'Disc repair', '', [], "# D\n");

    Livewire::test('docs.link-doc-modal', ['gameId' => $this->game->id])
        ->assertSee('Disc repair')
        ->call('link', $doc->path)
        ->assertDispatched('doc-linked', path: $doc->path);

    expect(app(DocLinks::class)->gamesFor($doc->path)->pluck('id')->all())->toBe([$this->game->id]);
});

test('the Docs page opens straight into the editor with ?edit=1', function () {
    $doc = $this->library->create('gc', 'Chapter guide', '', [], "# C\n\nBody.\n");

    $this->get(route('docs.index', ['doc' => $doc->path, 'edit' => 1]))->assertOk();

    $page = Livewire::withQueryParams(['doc' => $doc->path, 'edit' => '1'])
        ->test('docs.index')
        ->assertSet('editing', true);

    expect($page->get('draft'))->toContain('Body.');
});

test('the Docs page lists the doc\'s games, links one by title and unlinks it', function () {
    $doc = $this->library->create('gc', 'Chapter guide', '', [], "# C\n");

    Livewire::test('docs.link-game-modal', ['path' => $doc->path])
        ->set('search', 'paper')
        ->assertSee('Paper Mario')
        ->call('link', $this->game->id)
        ->assertDispatched('doc-linked');

    $page = Livewire::test('docs.index', ['path' => $doc->path]);

    expect($page->instance()->linkedGames->pluck('title')->all())->toBe(['Paper Mario']);

    $page->call('unlinkGame', $this->game->id);

    expect(app(DocLinks::class)->gamesFor($doc->path))->toBeEmpty();
});
