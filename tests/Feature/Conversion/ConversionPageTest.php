<?php

use App\Conversion\ConversionQueue;
use App\Enums\ConversionStatus;
use App\Enums\FileRole;
use App\Jobs\RunConversion;
use App\Models\ConsoleSourceFolder;
use App\Models\Conversion;
use App\Models\ConversionStat;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\User;
use App\Support\Console;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * The Conversion page: console tabs, the sources on each, the formats a picked
 * source can become, and the queue.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-page-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/ps2');
    File::ensureDirectoryExists($this->root.'/psx');
    File::ensureDirectoryExists($this->root.'/wii');
    File::ensureDirectoryExists($this->root.'/bin');
    config()->set('settings.games_path', $this->root);

    foreach (['chdman', 'maxcso', 'ecm', 'unecm', 'extract-xiso', 'cue2pops', 'pops2cue', 'nodtool'] as $tool) {
        File::put($this->root.'/bin/'.$tool, "#!/bin/sh\nexit 0\n");
        chmod($this->root.'/bin/'.$tool, 0755);
        config()->set('converters.tools.'.$tool.'.path', $this->root.'/bin/'.$tool);
    }

    ConsoleSourceFolder::add(new Console('ps2'));
    ConsoleSourceFolder::add(new Console('psx'));

    $this->actingAs(User::factory()->create());
    Queue::fake();
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function pageFile(string $console, string $name, int $size = 4096): GameFile
{
    File::ensureDirectoryExists(dirname(test()->root.'/'.$console.'/'.$name));
    File::put(test()->root.'/'.$console.'/'.$name, str_repeat("\0", $size));

    // Titled after the file, as the scanner titles a game it has not identified:
    // the list orders by title, and a random one would shuffle it.
    $game = Game::factory()->forConsole($console)->state([
        'title' => pathinfo($name, PATHINFO_FILENAME),
        'slug' => Str::slug($name),
    ]);

    return GameFile::factory()->for($game)->create([
        'path' => $console.'/'.$name,
        'filename' => basename($name),
        'extension' => Str::lower(pathinfo($name, PATHINFO_EXTENSION)),
        'size_bytes' => $size,
        'role' => FileRole::Rom,
    ]);
}

it('opens on the first console with something to convert, and lists its sources', function () {
    pageFile('ps2', 'Gran Turismo 4.iso');

    $this->get(route('tools.conversion'))
        ->assertOk()
        ->assertSee('Conversion')
        ->assertSee('Gran Turismo 4.iso')
        ->assertSee('Tool paths');
});

it('switches consoles by tab and searches the list', function () {
    pageFile('ps2', 'Gran Turismo 4.iso');
    pageFile('ps2', 'Okami.iso');
    pageFile('psx', 'Crash.bin', 2352);

    Livewire::test('tools.conversion')
        ->call('selectConsole', 'psx')
        ->assertSet('consoleKey', 'psx')
        ->assertSeeHtml('picker-psx');

    Livewire::test('conversion.picker', ['consoleKey' => 'psx'])
        ->assertSee('Crash.bin')
        ->assertDontSee('Okami.iso');

    Livewire::test('conversion.picker', ['consoleKey' => 'ps2'])
        ->set('search', 'oka')
        ->assertSee('Okami.iso')
        ->assertDontSee('Gran Turismo 4.iso');
});

it('offers only the formats the picked source can become, and picks the first', function () {
    $file = pageFile('ps2', 'Okami.iso');

    $page = Livewire::test('conversion.picker', ['consoleKey' => 'ps2'])
        ->call('toggleSource', $file->id)
        ->assertSet('format', 'chd-dvd')
        ->assertSee('.cso')
        ->assertSee('.zso')
        ->assertSee('Verify checksum')
        ->assertSee('Keep source')
        ->assertDontSee('.ecm');

    expect(collect($page->instance()->formats)->map(function (array $format): string {
        return $format['converter']->key();
    })->all())->toBe(['chd-dvd', 'cso', 'zso']);
});

it('greys out a format whose tool is missing', function () {
    config()->set('converters.tools.chdman.path', $this->root.'/bin/gone');
    $file = pageFile('ps2', 'Okami.iso');

    Livewire::test('conversion.picker', ['consoleKey' => 'ps2'])
        ->call('toggleSource', $file->id)
        ->assertSet('format', 'cso')
        ->assertSee('chdman is not installed');
});

it('queues the picked conversion with the options set', function () {
    $file = pageFile('ps2', 'Okami.iso');

    Livewire::test('conversion.picker', ['consoleKey' => 'ps2'])
        ->call('toggleSource', $file->id)
        ->call('selectFormat', 'zso')
        ->set('keepSource', false)
        ->call('add');

    $conversion = Conversion::query()->sole();

    expect($conversion->converter)->toBe('zso')
        ->and($conversion->option('keep_source'))->toBeFalse()
        ->and($conversion->directory)->toBe('');

    Queue::assertPushed(RunConversion::class);
});

it('will not queue a format the source was never offered', function () {
    $file = pageFile('psx', 'Crash.bin', 2352);

    $page = Livewire::test('conversion.picker', ['consoleKey' => 'psx'])->call('toggleSource', $file->id);

    // The format is locked: only selectFormat() sets it, and only to one on offer.
    expect(function () use ($page): void {
        $page->set('format', 'cso');
    })->toThrow(CannotUpdateLockedPropertyException::class);

    $page->call('selectFormat', 'cso')->call('add');

    expect(Conversion::query()->where('converter', 'cso')->count())->toBe(0);
});

it('refuses picks forged from the browser, and files of another game', function () {
    $mine = pageFile('ps2', 'Okami.iso');
    $theirs = pageFile('ps2', 'Gran Turismo 4.iso');

    $page = Livewire::test('conversion.picker', ['consoleKey' => 'ps2', 'gameId' => $mine->game_id]);

    expect(function () use ($page, $theirs): void {
        $page->set('sources', [$theirs->id]);
    })->toThrow(CannotUpdateLockedPropertyException::class);

    $page->call('toggleSource', $theirs->id);

    expect($page->get('sources'))->toBe([$mine->id]);
});

it('shows the queue, and cancels and clears from it', function () {
    $file = pageFile('ps2', 'Okami.iso');

    Livewire::test('conversion.picker', ['consoleKey' => 'ps2'])
        ->call('toggleSource', $file->id)
        ->call('add')
        ->assertDispatched('conversion-queued');

    $page = Livewire::test('conversion.queue')->assertSee('Queued');
    $conversion = Conversion::query()->sole();

    $page->call('cancel', $conversion->id)->assertSee('Cancelled');

    expect($conversion->fresh()->status)->toBe(ConversionStatus::Cancelled);

    $page->call('clearFinished')->assertSee('Nothing queued.');
});

it('shows how long a conversion has left over its bar, and how long it took once over', function () {
    $this->travelTo(now()->startOfMinute());
    $file = pageFile('ps2', 'Okami.iso');

    $conversion = Conversion::query()->create([
        'console' => 'ps2', 'converter' => 'cso', 'game_id' => $file->game_id, 'game_file_id' => $file->id,
        'label' => 'Okami.iso', 'sources' => ['ps2/Okami.iso'], 'options' => [],
        'status' => ConversionStatus::Running, 'progress' => 40, 'started_at' => now(),
    ]);

    Livewire::test('conversion.queue')->assertSee('Estimating…');

    $conversion->update(['eta_at' => now()->addSeconds(150)]);

    Livewire::test('conversion.queue')->assertSee('ETA 2min 30sec');

    $conversion->update(['status' => ConversionStatus::Done, 'eta_at' => null, 'finished_at' => now()->addSeconds(252)]);

    Livewire::test('conversion.queue')
        ->assertSee('Took 4min 12sec')
        ->assertDontSee('ETA')
        ->assertDontSee('Estimating…');

    $conversion->update(['status' => ConversionStatus::Cancelled, 'finished_at' => now()->addSeconds(63)]);

    Livewire::test('conversion.queue')->assertSee('Stopped after 1min 03sec');

    // Cancelled while it waited: it never ran, so there is no time to give.
    $conversion->update(['started_at' => null]);

    Livewire::test('conversion.queue')->assertDontSee('Stopped after');
});

it('folds each format\'s tool settings under Advanced options, at their defaults', function () {
    $file = pageFile('ps2', 'Okami.iso');

    Livewire::test('conversion.picker', ['consoleKey' => 'ps2'])
        ->call('toggleSource', $file->id)
        ->call('selectFormat', 'zso')
        ->assertSee('Advanced options')
        ->assertSee('Block size (--block)')
        ->assertSet('advanced', ['block' => '2048', 'threads' => 'auto'])
        ->set('advanced.block', '4096')
        ->call('add');

    expect(Conversion::query()->sole()->options['advanced'])->toBe(['block' => '4096', 'threads' => 'auto']);
});

it('estimates the output size only once that conversion has taught it one', function () {
    $file = pageFile('ps2', 'Okami.iso', 1000);

    Livewire::test('conversion.picker', ['consoleKey' => 'ps2'])
        ->call('toggleSource', $file->id)
        ->assertDontSee('≈');

    ConversionStat::record('cso', 'ps2', 1000, 900);

    Livewire::test('conversion.picker', ['consoleKey' => 'ps2'])
        ->call('toggleSource', $file->id)
        ->assertSee('≈ '.Number::fileSize(900, 1));
});

it('says what a finished conversion saved, or how much it grew', function () {
    $file = pageFile('ps2', 'Okami.iso');

    $conversion = Conversion::query()->create([
        'console' => 'ps2', 'converter' => 'cso', 'game_id' => $file->game_id, 'game_file_id' => $file->id,
        'label' => 'Okami.iso', 'sources' => ['ps2/Okami.iso'], 'options' => [],
        'status' => ConversionStatus::Running, 'source_bytes' => 4000, 'output_bytes' => 3000,
    ]);

    Livewire::test('conversion.queue')->assertDontSee('saved');

    $conversion->update(['status' => ConversionStatus::Done]);

    Livewire::test('conversion.queue')
        ->assertSee(Number::fileSize(4000, 1).' → '.Number::fileSize(3000, 1).' · saved '.Number::fileSize(1000, 1).' (25%)');

    $conversion->update(['source_bytes' => 3000, 'output_bytes' => 4000]);

    Livewire::test('conversion.queue')->assertSee('grew '.Number::fileSize(1000, 1).' (33%)');
});

it('offers a PS1 cue sheet as a VCD, and lists the VCD tools', function () {
    $sheet = pageFile('psx', 'Crash.cue', 80);
    $sheet->update(['role' => FileRole::Sheet]);
    File::put($this->root.'/psx/Crash.cue', "FILE \"Crash.bin\" BINARY\n  TRACK 01 MODE2/2352\n    INDEX 01 00:00:00\n");

    Livewire::test('conversion.picker', ['consoleKey' => 'psx'])
        ->call('toggleSource', $sheet->id)
        ->assertSee('.vcd')
        ->assertSee('cue2pops')
        ->call('selectFormat', 'vcd')
        ->assertSee('PAL to NTSC patch (vmode)');

    // Both tools are listed under Tool paths, on the page.
    $this->get(route('tools.conversion', ['console' => 'psx']))->assertSee('cue2pops')->assertSee('pops2cue');
});

it('sits under Tools in the menu, and sends the old Builder address there', function () {
    $this->get(route('tools.conversion'))
        ->assertOk()
        ->assertSee('Tools')
        ->assertSee(route('tools.conversion'), false);

    $this->get('/builder')->assertRedirect(route('tools.conversion'));
});

it('offers a Wii disc RVZ and WBFS, with the zstd level under Advanced options', function () {
    ConsoleSourceFolder::add(new Console('wii'));
    $file = pageFile('wii', 'Zelda.iso');

    Livewire::test('conversion.picker', ['consoleKey' => 'wii'])
        ->call('toggleSource', $file->id)
        ->assertSee('.rvz')
        ->assertSee('.wbfs')
        ->assertSet('format', 'rvz')
        ->assertSee('Zstandard level (-c zstd:N)');
});

it('queues every picked game at once, one conversion each', function () {
    $okami = pageFile('ps2', 'Okami.iso');
    $gt4 = pageFile('ps2', 'Gran Turismo 4.iso');

    Livewire::test('conversion.picker', ['consoleKey' => 'ps2'])
        ->call('toggleSource', $okami->id)
        ->call('toggleSource', $gt4->id)
        ->assertSee('2 files')
        ->call('selectFormat', 'zso')
        ->assertSee('Add 2 to queue')
        ->call('add')
        ->assertSet('sources', []);

    expect(Conversion::query()->where('converter', 'zso')->pluck('label')->sort()->values()->all())
        ->toBe(['Gran Turismo 4.iso', 'Okami.iso']);

    Queue::assertPushed(RunConversion::class, 2);
});

it('offers every format any pick can become, and leaves out the picks it does not fit', function () {
    // Both .img, but one of CD sectors and one of DVD sectors.
    $cd = pageFile('ps2', 'Raw.img', 2352 * 10);
    $dvd = pageFile('ps2', 'Dvd.img', 2048 * 11);

    $page = Livewire::test('conversion.picker', ['consoleKey' => 'ps2'])
        ->call('toggleSource', $cd->id)
        ->call('toggleSource', $dvd->id);

    $formats = collect($page->instance()->formats)->mapWithKeys(function (array $format): array {
        return [$format['converter']->key() => $format['fits']];
    })->all();

    expect($formats)->toBe(['chd-cd' => 1, 'chd-dvd' => 1]);

    $page->call('selectFormat', 'chd-cd')
        ->assertSee('Fits 1 of 2 picked')
        ->call('add');

    expect(Conversion::query()->pluck('label')->all())->toBe(['Raw.img']);
});

it('picks everything the search shows, and puts it all back', function () {
    pageFile('ps2', 'Okami.iso');
    pageFile('ps2', 'Okage.iso');
    pageFile('ps2', 'Gran Turismo 4.iso');

    $page = Livewire::test('conversion.picker', ['consoleKey' => 'ps2'])
        ->set('search', 'oka')
        ->call('toggleAll');

    expect($page->get('sources'))->toHaveCount(2)
        ->and($page->instance()->pickState)->toBe('on');

    $page->call('toggleAll');

    expect($page->get('sources'))->toBe([]);
});

it('lists a game kept in several files once, with its files under it', function () {
    $iso = pageFile('ps2', 'Batman Begins (Europe).iso');
    $iso->game->update(['title' => 'Batman Begins']);
    File::put($this->root.'/ps2/Batman Begins (Europe).zso', str_repeat("\0", 2048));
    $zso = GameFile::factory()->for($iso->game)->create([
        'path' => 'ps2/Batman Begins (Europe).zso', 'filename' => 'Batman Begins (Europe).zso',
        'extension' => 'zso', 'size_bytes' => 2048, 'role' => FileRole::Rom,
    ]);
    pageFile('ps2', 'Okami.iso');

    $page = Livewire::test('conversion.picker', ['consoleKey' => 'ps2'])
        ->assertSee('Batman Begins')
        ->assertSee('2 files')
        ->assertSee('Batman Begins (Europe).iso')
        ->assertSee('Batman Begins (Europe).zso')
        ->assertSee('Okami.iso');

    expect($page->instance()->games)->toHaveCount(2)
        ->and(Arr::get($page->instance()->games->first(), 'sets')->pluck('file.id')->sort()->values()->all())->toBe([$iso->id, $zso->id]);
});

it('searches by the game\'s title as well as its files\' names', function () {
    $file = pageFile('ps2', 'SLES_503.30.iso');
    $file->game->update(['title' => 'Final Fantasy X']);
    pageFile('ps2', 'Okami.iso');

    Livewire::test('conversion.picker', ['consoleKey' => 'ps2'])
        ->set('search', 'fantasy')
        ->assertSee('SLES_503.30.iso')
        ->assertDontSee('Okami.iso');
});

it('narrows the list to one file type, and offers the types on the console', function () {
    pageFile('ps2', 'Okami.iso');
    pageFile('ps2', 'Shadow.cso');

    $page = Livewire::test('conversion.picker', ['consoleKey' => 'ps2'])
        ->assertSee('All types');

    expect($page->instance()->types)->toBe(['cso', 'iso']);

    $page->set('type', 'cso')
        ->assertSee('Shadow.cso')
        ->assertDontSee('Okami.iso')
        ->call('toggleAll');

    expect($page->get('sources'))->toHaveCount(1);
});

it('picks only files of one format together', function () {
    $iso = pageFile('ps2', 'Okami.iso');
    $cso = pageFile('ps2', 'Shadow.cso');
    $gt4 = pageFile('ps2', 'Gran Turismo 4.iso');

    $page = Livewire::test('conversion.picker', ['consoleKey' => 'ps2'])
        ->call('toggleSource', $iso->id)
        ->call('toggleSource', $cso->id)
        ->call('toggleSource', $gt4->id);

    expect($page->get('sources'))->toBe([$iso->id, $gt4->id])
        ->and($page->instance()->pickedFormat)->toBe('iso');

    // Cleared, the other format can be picked.
    $page->call('clearSelection')->call('toggleSource', $cso->id);

    expect($page->get('sources'))->toBe([$cso->id]);
});

it('selects all of one format only, the first row\'s when nothing is picked', function () {
    $gt4 = pageFile('ps2', 'Gran Turismo 4.iso');
    $okami = pageFile('ps2', 'Okami.iso');
    pageFile('ps2', 'Shadow.cso');

    $page = Livewire::test('conversion.picker', ['consoleKey' => 'ps2'])->call('toggleAll');

    expect(collect($page->get('sources'))->sort()->values()->all())->toBe(collect([$gt4->id, $okami->id])->sort()->values()->all())
        ->and($page->instance()->pickState)->toBe('on');
});

it('shows the conversion queue on the sidebar\'s Toolbox - Conversion row as the one running of total and a percent', function () {
    $file = pageFile('ps2', 'Okami.iso');
    $row = [
        'console' => 'ps2', 'converter' => 'cso', 'game_id' => $file->game_id, 'game_file_id' => $file->id,
        'label' => 'Okami.iso', 'sources' => ['ps2/Okami.iso'], 'options' => [], 'queued_at' => now(),
    ];

    Livewire::test('system-activity')->assertDontSeeText('/3');

    Conversion::query()->create([...$row, 'status' => ConversionStatus::Done, 'progress' => 100]);
    Conversion::query()->create([...$row, 'status' => ConversionStatus::Running, 'progress' => 50]);
    Conversion::query()->create([...$row, 'status' => ConversionStatus::Queued]);

    // One finished, the second half way, one to go: on 2 of 3, (1 + 0.5) / 3.
    Livewire::test('system-activity')->assertSeeText('2/3 · 50%');
});

it('counts a retried conversion in the batch it rejoins, not everything since it was first asked for', function () {
    $file = pageFile('ps2', 'Okami.iso');
    $row = [
        'console' => 'ps2', 'converter' => 'cso', 'game_id' => $file->game_id, 'game_file_id' => $file->id,
        'label' => 'Okami.iso', 'sources' => ['ps2/Okami.iso'], 'options' => [],
    ];

    // An old failure, then a week of finished conversions after it.
    $old = Conversion::query()->create([...$row, 'status' => ConversionStatus::Failed, 'queued_at' => now()->subWeek()]);

    foreach (range(1, 5) as $day) {
        Conversion::query()->create([...$row, 'status' => ConversionStatus::Done, 'progress' => 100, 'queued_at' => now()->subDays($day)]);
    }

    app(ConversionQueue::class)->retry($old);

    Livewire::test('system-activity')->assertSeeText('0/1 · 0%');
});

it('keeps a conversion\'s log off the page until it is opened', function () {
    $file = pageFile('ps2', 'Okami.iso');
    $conversion = Conversion::query()->create([
        'console' => 'ps2', 'converter' => 'cso', 'game_id' => $file->game_id, 'game_file_id' => $file->id,
        'label' => 'Okami.iso', 'sources' => ['ps2/Okami.iso'], 'options' => [], 'queued_at' => now(),
        'status' => ConversionStatus::Done, 'log' => "\$ maxcso --block=2048 a very particular line\n",
    ]);

    Livewire::test('conversion.queue')
        ->assertDontSee('a very particular line')
        ->call('toggleLog', $conversion->id)
        ->assertSee('a very particular line')
        ->call('toggleLog', $conversion->id)
        ->assertDontSee('a very particular line');
});

it('opens with the shelf\'s picks in the search, listing only them and ticking nothing', function () {
    $okami = pageFile('ps2', 'Okami.iso');
    pageFile('ps2', 'Gran Turismo 4.iso');
    $shadow = pageFile('ps2', 'Shadow.iso');

    Livewire::withQueryParams(['console' => 'ps2', 'search' => $okami->game_id.','.$shadow->game_id])
        ->test('tools.conversion')
        ->assertSee('Okami.iso')
        ->assertSee('Shadow.iso')
        ->assertDontSee('Gran Turismo 4.iso');

    $picker = Livewire::test('conversion.picker', ['consoleKey' => 'ps2', 'search' => $okami->game_id.', '.$shadow->game_id])
        ->assertSet('sources', []);

    expect($picker->instance()->searchedIds)->toBe([$okami->game_id, $shadow->game_id])
        ->and($picker->instance()->sets->pluck('file.id')->all())->toBe([$okami->id, $shadow->id]);

    // Cleared, it is the whole console again.
    $picker->set('search', '')->assertSee('Gran Turismo 4.iso');
});

it('reads a search of ids as ids, and anything else as text', function () {
    $okami = pageFile('ps2', 'Okami.iso');
    pageFile('ps2', '1942.iso');

    $picker = Livewire::test('conversion.picker', ['consoleKey' => 'ps2']);

    expect($picker->set('search', 'oka, 12')->instance()->searchedIds)->toBeNull();

    // A lone number is an id and a title both.
    $picker->set('search', '1942')->assertSee('1942.iso')->assertDontSee('Okami.iso');
    $picker->set('search', (string) $okami->game_id)->assertSee('Okami.iso');
});

it('starts the search on text from the address too', function () {
    pageFile('ps2', 'Okami.iso');
    pageFile('ps2', 'Gran Turismo 4.iso');

    Livewire::withQueryParams(['console' => 'ps2', 'search' => 'oka'])
        ->test('tools.conversion')
        ->assertSee('Okami.iso')
        ->assertDontSee('Gran Turismo 4.iso');
});

it('forgets the shelf\'s picks on another console', function () {
    $okami = pageFile('ps2', 'Okami.iso');

    Livewire::withQueryParams(['console' => 'ps2', 'search' => (string) $okami->game_id])
        ->test('tools.conversion')
        ->call('selectConsole', 'psx')
        ->assertSet('search', '');
});

it('draws each console tab with a working selectConsole call', function () {
    $this->get(route('tools.conversion'))
        ->assertSee("selectConsole('ps2')", false)
        ->assertSee("selectConsole('psx')", false)
        ->assertDontSee('@js(', false);
});
