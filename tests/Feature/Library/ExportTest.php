<?php

use App\Enums\GameStatus;
use App\Exceptions\LibraryPathException;
use App\Jobs\WriteConsoleExports;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\Media;
use App\Support\Console;
use App\Support\ExportProgress;
use App\Support\LibraryPath;
use App\Tools\ConsoleTools;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The only thing in retroBITE that writes into somebody's game library.
 *
 * So the assertions are mostly about restraint: it writes into CFG/ and ART/
 * and nowhere else, it never deletes, it keeps the OPL settings a person tuned
 * by hand, and it declines entirely on a console arranged some other way. The
 * shape of what it writes is checked against the files on a real drive.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-export-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/ps2/DVD');
    config()->set('settings.games_path', $this->root);

    Storage::fake('media');

    $this->console = new Console('ps2');
    ConsoleSourceFolder::add($this->console, null, 'opl');
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

/**
 * Run one export the way every caller does, through the chain.
 *
 * @return array{written: int, skipped: int, failed: int}
 */
function runExport(string $export, bool $force = false): array
{
    return ConsoleTools::for(test()->console)->export($export)->force($force)->run();
}

function exportGame(array $attributes = [], string $serial = 'SLES_503.86'): Game
{
    $game = Game::factory()->create(array_merge([
        'console' => 'ps2',
        'status' => GameStatus::Matched,
        'title' => 'Crash Bandicoot - The Wrath of Cortex',
        'genre' => 'Platform',
        'release_date' => '2001-11-01',
        'developer' => "Traveller's Tales",
        'description' => 'Crash Bandicoot: The Wrath of Cortex is the first Crash Bandicoot game for a system other than the original PlayStation.',
    ], $attributes));

    GameFile::factory()->for($game)->withMeta(['license_id' => $serial])->create([
        'path' => 'ps2/DVD/'.$serial.'.Game.iso',
        'filename' => $serial.'.Game.iso',
        'extension' => 'iso',
    ]);

    return $game;
}

function cfg(string $serial = 'SLES_503.86'): string
{
    return (string) file_get_contents(test()->root.'/ps2/CFG/'.$serial.'.cfg');
}

/** A PNG of a given size, so nothing has to be committed as a fixture. */
function pngOf(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 200, 30, 30));

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function screenshot(Game $game, string $type, string $contents): Media
{
    $path = 'ps2/game/'.$type.'/'.md5($contents).'.png';
    Storage::disk('media')->put($path, $contents);

    return Media::factory()->for($game)->create([
        'screenscraper_type' => $type,
        'path' => $path,
        'extension' => 'png',
        'md5' => md5($contents),
    ]);
}

function disc(Game $game, string $contents): Media
{
    $path = 'ps2/game/support-2D/'.md5($contents).'.png';
    Storage::disk('media')->put($path, $contents);

    return Media::factory()->for($game)->create([
        'screenscraper_type' => 'support-2D',
        'path' => $path,
        'extension' => 'png',
        'md5' => md5($contents),
    ]);
}

/** A disc scan: an opaque circle on a clear square, as ScreenScraper sends one. */
function discScan(int $size = 600): string
{
    $image = imagecreatetruecolor($size, $size);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledellipse($image, intdiv($size, 2), intdiv($size, 2), $size, $size, (int) imagecolorallocate($image, 40, 80, 200));

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function cover(Game $game, string $contents): Media
{
    $path = 'ps2/game/box-2D/'.md5($contents).'.png';
    Storage::disk('media')->put($path, $contents);

    return Media::factory()->for($game)->create([
        'screenscraper_type' => 'box-2D',
        'path' => $path,
        'extension' => 'png',
        'md5' => md5($contents),
    ]);
}

it('writes a config in the shape a real drive carries', function () {
    exportGame();

    expect(runExport('cfg'))
        ->toBe(['written' => 1, 'skipped' => 0, 'failed' => 0]);

    expect(cfg())->toBe(implode("\n", [
        'Title=Crash Bandicoot - The Wrath of Cortex',
        'Genre=Platform',
        'Release=2001-11-01',
        "Developer=Traveller's Tales",
        'Description=Crash Bandicoot: The Wrath of Cortex is the first Crash Bandicoot game for a system other than the original PlayStation.',
        '#LongName=Crash Bandicoot - The Wrath of Cortex',
    ])."\n");
});

it('writes the rating as the whole stars OPL draws', function (?int $rating, ?string $line) {
    exportGame(['rating' => $rating]);

    runExport('cfg');

    $line === null
        ? expect(cfg())->not->toContain('Rating=')
        : expect(cfg())->toContain("\nDeveloper=Traveller's Tales\n{$line}\nDescription=");
})->with([
    'the game page\'s 85' => [85, 'Rating=4'],
    'half way rounds up' => [50, 'Rating=3'],
    'the floor' => [0, 'Rating=0'],
    'the ceiling' => [100, 'Rating=5'],
    'nobody has rated it' => [null, null],
]);

it('cuts a long synopsis on a word boundary', function () {
    exportGame(['description' => str_repeat('word ', 200)]);

    runExport('cfg');

    $description = Str::after(Str::before(cfg(), "\n#LongName="), 'Description=');

    expect($description)->toEndWith('...')
        ->and(mb_strlen($description))->toBeLessThanOrEqual(303)
        // Cut between words, never mid-word.
        ->and($description)->not->toContain('wor...');
});

it('writes plain ascii, because that is all OPL draws', function () {
    exportGame(['title' => 'Pokémon Colosseum', 'developer' => 'Genius Sonority']);

    runExport('cfg');

    expect(cfg())->toContain('Title=Pokemon Colosseum')
        ->and(mb_check_encoding(cfg(), 'ASCII'))->toBeTrue();
});

it('leaves out a field it has no answer for', function () {
    exportGame(['genre' => null, 'developer' => null]);

    runExport('cfg');

    // Written blank, OPL shows the key with nothing after it, which reads as
    // an answer rather than an absence.
    expect(cfg())->not->toContain('Genre=')
        ->and(cfg())->not->toContain('Developer=');
});

it('keeps the settings somebody tuned by hand', function () {
    exportGame();

    File::ensureDirectoryExists($this->root.'/ps2/CFG');
    File::put($this->root.'/ps2/CFG/SLES_503.86.cfg', "Title=Old\n\$DMA=1\n\$Compatibility=4\n");

    runExport('cfg', force: true);

    // Dropping these resets a game somebody got running by trial and error,
    // which is the one genuinely damaging thing this feature could do.
    expect(cfg())->toContain('$DMA=1')
        ->and(cfg())->toContain('$Compatibility=4')
        ->and(cfg())->toContain('Title=Crash Bandicoot - The Wrath of Cortex')
        ->and(cfg())->not->toContain('Title=Old');
});

it('does not rewrite a config it has already written', function () {
    exportGame();

    runExport('cfg');
    $first = cfg();

    expect(runExport('cfg'))
        ->toBe(['written' => 0, 'skipped' => 1, 'failed' => 0])
        ->and(cfg())->toBe($first);
});

it('skips a game with nothing to name a file by', function () {
    // Not identified: its title is still its filename, and writing that back
    // out as metadata would be worse than writing nothing.
    $placeholder = Game::factory()->create(['console' => 'ps2', 'status' => GameStatus::Placeholder]);
    GameFile::factory()->for($placeholder)->withMeta(['license_id' => 'SLES_111.11'])->create([
        'path' => 'ps2/DVD/Unknown.iso', 'extension' => 'iso',
    ]);

    // Identified, but its serial has never been read off the disc.
    $unread = Game::factory()->create(['console' => 'ps2', 'status' => GameStatus::Matched]);
    GameFile::factory()->for($unread)->create(['path' => 'ps2/DVD/Known.iso', 'extension' => 'iso']);

    expect(runExport('cfg'))
        ->toBe(['written' => 0, 'skipped' => 2, 'failed' => 0])
        ->and(File::exists($this->root.'/ps2/CFG'))->toBeFalse();
});

it('writes nothing at all for a console arranged some other way', function () {
    exportGame();
    ConsoleSourceFolder::setLayout($this->console, 'custom');

    expect(runExport('cfg'))
        ->toBe(['written' => 0, 'skipped' => 0, 'failed' => 0])
        ->and(runExport('art'))
        ->toBe(['written' => 0, 'skipped' => 0, 'failed' => 0])
        ->and(File::exists($this->root.'/ps2/CFG'))->toBeFalse()
        ->and(File::exists($this->root.'/ps2/ART'))->toBeFalse();
});

it('re-encodes a cached cover to the size OPL reads', function () {
    $game = exportGame();
    cover($game, pngOf(1000, 1400));

    expect(runExport('art'))
        ->toBe(['written' => 1, 'skipped' => 0, 'failed' => 0]);

    $path = $this->root.'/ps2/ART/SLES_503.86_COV.png';
    $size = getimagesize($path);

    // The 1.2 builds carry libpng and no JPEG decoder: a _COV.jpg is never
    // drawn, which is how a whole drive's covers went missing once.
    expect($size[0])->toBe(256)
        ->and($size[1])->toBe(368)
        ->and($size['mime'])->toBe('image/png')
        ->and(File::exists($this->root.'/ps2/ART/SLES_503.86_COV.jpg'))->toBeFalse();
});

it('writes the disc scan as the icon OPL draws beside the cover, its corners clear', function () {
    $game = exportGame();
    cover($game, pngOf(1000, 1400));
    disc($game, discScan());

    expect(runExport('art'))
        ->toBe(['written' => 1, 'skipped' => 0, 'failed' => 0]);

    $path = $this->root.'/ps2/ART/SLES_503.86_ICO.png';
    $size = getimagesize($path);

    expect($size[0])->toBe(128)
        ->and($size[1])->toBe(128)
        ->and($size['mime'])->toBe('image/png')
        ->and(File::exists($this->root.'/ps2/ART/SLES_503.86_COV.png'))->toBeTrue();

    $written = imagecreatefrompng($path);
    $corner = imagecolorsforindex($written, (int) imagecolorat($written, 0, 0));
    $middle = imagecolorsforindex($written, (int) imagecolorat($written, 64, 64));

    // Round like OPL's own default disc, which is a 128-square with clear
    // corners: a flattened square would draw a black box round every disc.
    expect($corner['alpha'])->toBe(127)
        ->and($middle['alpha'])->toBe(0)
        ->and($middle['blue'])->toBeGreaterThan(150);
});

it('writes the cover alone for a game with no disc scan', function () {
    $game = exportGame();
    cover($game, pngOf(1000, 1400));

    runExport('art');

    expect(File::exists($this->root.'/ps2/ART/SLES_503.86_COV.png'))->toBeTrue()
        ->and(File::exists($this->root.'/ps2/ART/SLES_503.86_ICO.png'))->toBeFalse();
});

it('writes the disc alone for a game with no cover', function () {
    $game = exportGame();
    disc($game, discScan());

    expect(runExport('art'))
        ->toBe(['written' => 1, 'skipped' => 0, 'failed' => 0])
        ->and(File::exists($this->root.'/ps2/ART/SLES_503.86_ICO.png'))->toBeTrue();
});

it('flattens a transparent cover onto something opaque', function () {
    $game = exportGame();

    $source = imagecreatetruecolor(500, 700);
    imagesavealpha($source, true);
    imagefill($source, 0, 0, (int) imagecolorallocatealpha($source, 0, 0, 0, 127));
    ob_start();
    imagepng($source);
    cover($game, (string) ob_get_clean());

    runExport('art');

    $written = imagecreatefrompng($this->root.'/ps2/ART/SLES_503.86_COV.png');
    $colour = imagecolorsforindex($written, (int) imagecolorat($written, 128, 184));

    // A cover is drawn opaque: whatever is transparent arrives on the console
    // as whatever happened to be in that memory. The source was uniformly
    // transparent, so a flattened frame is flat, opaque black.
    expect($colour['alpha'])->toBe(0)
        ->and($colour['red'])->toBe(0)
        ->and($colour['green'])->toBe(0)
        ->and($colour['blue'])->toBe(0);
});

it('writes the in-game and title screenshots for the info page', function () {
    $game = exportGame();
    screenshot($game, 'ss', pngOf(640, 480));
    screenshot($game, 'sstitle', pngOf(640, 448));

    expect(runExport('art'))
        ->toBe(['written' => 1, 'skipped' => 0, 'failed' => 0]);

    foreach (['_SCR', '_SCR2'] as $suffix) {
        $size = getimagesize($this->root.'/ps2/ART/SLES_503.86'.$suffix.'.png');

        expect($size[0])->toBe(173)
            ->and($size[1])->toBe(148)
            ->and($size['mime'])->toBe('image/png');
    }
});

it('writes only the screenshots a game has', function () {
    $game = exportGame();
    screenshot($game, 'ss', pngOf(640, 480));

    runExport('art');

    expect(File::exists($this->root.'/ps2/ART/SLES_503.86_SCR.png'))->toBeTrue()
        ->and(File::exists($this->root.'/ps2/ART/SLES_503.86_SCR2.png'))->toBeFalse();
});

it('does not fetch artwork it was never given', function () {
    exportGame();

    // A cover that was never scraped is a skip, not a provider request. A
    // quota spend hidden behind a file-export button is nobody's idea.
    expect(runExport('art'))
        ->toBe(['written' => 0, 'skipped' => 1, 'failed' => 0])
        ->and(File::exists($this->root.'/ps2/ART'))->toBeFalse();
});

it('does not re-encode art it has already written', function () {
    $game = exportGame();
    cover($game, pngOf(1000, 1400));

    runExport('art');
    $first = (string) file_get_contents($this->root.'/ps2/ART/SLES_503.86_COV.png');

    expect(runExport('art'))
        ->toBe(['written' => 0, 'skipped' => 1, 'failed' => 0])
        ->and((string) file_get_contents($this->root.'/ps2/ART/SLES_503.86_COV.png'))->toBe($first);
});

it('counts a broken cover as a failure and keeps going', function () {
    $good = exportGame(serial: 'SLES_503.86');
    cover($good, pngOf(400, 560));

    $bad = exportGame(['title' => 'Broken'], serial: 'SLES_512.22');
    cover($bad, 'this is not a png');

    $counts = runExport('art');

    expect($counts['written'])->toBe(1)
        ->and($counts['failed'])->toBe(1)
        ->and(File::exists($this->root.'/ps2/ART/SLES_503.86_COV.png'))->toBeTrue();
});

it('refuses a path that climbs out of the console folder', function () {
    $gate = app(LibraryPath::class);

    expect(fn () => $gate->within($this->console, '../../etc/passwd'))
        ->toThrow(LibraryPathException::class)
        ->and(fn () => $gate->within($this->console, '/etc/passwd'))
        ->toThrow(LibraryPathException::class)
        ->and(fn () => $gate->within($this->console, 'CFG/../../../outside.cfg'))
        ->toThrow(LibraryPathException::class)
        ->and($gate->within($this->console, 'CFG/SLES_503.86.cfg'))
        ->toBe('ps2/CFG/SLES_503.86.cfg');
});

it('runs an export from the job only for a console that offers it', function () {
    $game = exportGame();
    cover($game, pngOf(400, 560));

    (new WriteConsoleExports('ps2', 'cfg'))->handle();
    (new WriteConsoleExports('ps2', 'art'))->handle();
    (new WriteConsoleExports('snes', 'cfg'))->handle();

    // The SNES has no loader files to write, so the job touches nothing.
    expect(File::exists($this->root.'/ps2/CFG/SLES_503.86.cfg'))->toBeTrue()
        ->and(File::exists($this->root.'/ps2/ART/SLES_503.86_COV.png'))->toBeTrue()
        ->and(File::exists($this->root.'/snes'))->toBeFalse();
});

it('counts files rather than jobs while an export runs', function () {
    exportGame(['title' => 'One'], 'SLES_503.86');
    exportGame(['title' => 'Two'], 'SLES_504.86');
    exportGame(['title' => 'Three'], 'SLES_505.86');

    $seen = [];

    ConsoleTools::for($this->console)
        ->export('cfg')
        ->onProgress(function (int $done, int $total) use (&$seen): void {
            $seen[] = [$done, $total];
        })
        ->run();

    // Three games, four reports: the zero the bar starts at and one per game.
    expect($seen)->toBe([[0, 3], [1, 3], [2, 3], [3, 3]]);
});

it('leaves nothing on the page once the export is over', function () {
    exportGame();

    (new WriteConsoleExports('ps2', 'cfg'))->handle();

    expect(ExportProgress::all())->toBe([]);
});

it('stops reporting an export that threw', function () {
    exportGame();

    // A console whose folder went away mid-run: the job must still clear up.
    File::deleteDirectory($this->root.'/ps2');

    try {
        (new WriteConsoleExports('ps2', 'cfg'))->handle();
    } catch (Throwable) {
        // The assertion is about what was left behind, not what was thrown.
    }

    expect(ExportProgress::all())->toBe([]);
});
