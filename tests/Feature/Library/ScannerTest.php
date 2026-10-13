<?php

use App\Enums\FileArchiveReason;
use App\Enums\FileRole;
use App\Enums\GameStatus;
use App\Exceptions\ScanAborted;
use App\Jobs\MatchGame;
use App\Jobs\ScanConsoleFolder;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Services\LibraryScanner;
use App\Support\Console;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-scan-'.Str::random(8);
    File::ensureDirectoryExists($this->root);
    config()->set('settings.games_path', $this->root);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function put(string $relative, string $contents = 'x'): string
{
    $path = test()->root.'/'.$relative;
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);

    return $path;
}

function scan(string $console = 'psx')
{
    return app(LibraryScanner::class)->scan(new Console($console));
}

it('records a loose rom as one game with one file', function () {
    put('snes/Super Mario World.sfc');

    $result = scan('snes');

    expect($result->gamesCreated)->toBe(1)
        ->and($result->filesCreated)->toBe(1);

    $game = Game::sole();

    expect($game->title)->toBe('Super Mario World')
        ->and($game->slug)->toBe('super-mario-world')
        ->and($game->status)->toBe(GameStatus::Placeholder)
        ->and($game->console)->toBe('snes')
        ->and($game->files()->sole()->role)->toBe(FileRole::Rom)
        // Relative to the library root, so remounting elsewhere changes nothing.
        ->and($game->files()->sole()->path)->toBe('snes/Super Mario World.sfc');
});

it('reads a playlist first so nine files become one game', function () {
    // The case the whole pass order exists for: walking this folder flat would
    // make nine games and spend nine lookups to discover eight were wrong.
    put('psx/FF9/Final Fantasy IX.m3u', "#EXTM3U\nFF9 (Disc 1).cue\nFF9 (Disc 2).cue\nFF9 (Disc 3).cue\nFF9 (Disc 4).cue\n");

    foreach (range(1, 4) as $disc) {
        put("psx/FF9/FF9 (Disc {$disc}).cue", "FILE \"FF9 (Disc {$disc}).bin\" BINARY\n  TRACK 01 MODE2/2352\n");
        put("psx/FF9/FF9 (Disc {$disc}).bin");
    }

    $result = scan('psx');

    expect($result->gamesCreated)->toBe(1)
        ->and($result->filesCreated)->toBe(9);

    $game = Game::sole();

    expect($game->title)->toBe('Final Fantasy IX');

    $playlist = $game->files()->where('role', FileRole::Playlist)->sole();
    $sheets = $game->files()->where('role', FileRole::Sheet)->get();
    $tracks = $game->files()->where('role', FileRole::Track)->get();

    expect($sheets)->toHaveCount(4)
        ->and($tracks)->toHaveCount(4)
        ->and($sheets->pluck('parent_id')->unique()->all())->toBe([$playlist->id])
        ->and($sheets->pluck('disc_number')->all())->toBe([1, 2, 3, 4])
        // A track hangs off its own sheet, not off the playlist.
        ->and($tracks->pluck('parent_id')->sort()->values()->all())->toBe($sheets->pluck('id')->sort()->values()->all());
});

it('gives one lookup candidate for a whole multi-disc set', function () {
    put('psx/FF9/Final Fantasy IX.m3u', "FF9 (Disc 1).bin\nFF9 (Disc 2).bin\n");
    put('psx/FF9/FF9 (Disc 1).bin');
    put('psx/FF9/FF9 (Disc 2).bin');

    scan('psx');

    $candidate = Game::sole()->identifiableFile();

    expect($candidate->disc_number)->toBe(1)
        ->and($candidate->role)->toBe(FileRole::Rom);
});

it('leaves a cue/bin disc set with a file it can actually look up', function () {
    // Caught by running the scanner for real, not by the tests above: the
    // playlist names cuesheets, whose tracks are the .bin files, so a set like
    // this has no whole-image file at all. If tracks did not count as
    // identifiable the game could never be matched, silently and forever.
    put('psx/FF9/Final Fantasy IX.m3u', "FF9 (Disc 1).cue\nFF9 (Disc 2).cue\n");

    foreach (range(1, 2) as $disc) {
        put("psx/FF9/FF9 (Disc {$disc}).cue", "FILE \"FF9 (Disc {$disc}).bin\" BINARY\n");
        put("psx/FF9/FF9 (Disc {$disc}).bin");
    }

    scan('psx');

    $candidate = Game::sole()->identifiableFile();

    // Disc 1's data track: what ScreenScraper indexes with a correct size and
    // checksum, unlike the .m3u and the .cue.
    expect($candidate)->not->toBeNull()
        ->and($candidate->filename)->toBe('FF9 (Disc 1).bin')
        ->and($candidate->role)->toBe(FileRole::Track)
        ->and($candidate->disc_number)->toBe(1);
});

it('prefers a whole image over a track when a game has both', function () {
    put('psx/Game.iso');
    put('psx/Game.cue', "FILE \"Game (Track 01).bin\" BINARY\n");
    put('psx/Game (Track 01).bin');

    scan('psx');

    $game = Game::where('slug', 'game')->sole();

    expect($game->identifiableFile()->role)->toBe(FileRole::Rom);
});

it('treats a cuesheet with no playlist as its own game', function () {
    put('psx/Tekken 3.cue', "FILE \"Tekken 3 (Track 01).bin\" BINARY\nFILE \"Tekken 3 (Track 02).bin\" BINARY\n");
    put('psx/Tekken 3 (Track 01).bin');
    put('psx/Tekken 3 (Track 02).bin');

    $result = scan('psx');

    // The tracks carry a psx file extension, so a flat walk would have made each
    // a game of its own and spent a lookup on it.
    expect($result->gamesCreated)->toBe(1)
        ->and(Game::sole()->title)->toBe('Tekken 3')
        ->and(GameFile::where('role', FileRole::Track)->count())->toBe(2);
});

it('makes one game of loose cuesheets that say they are discs of one set', function () {
    foreach ([1, 2] as $disc) {
        put("psx/Final Fantasy VII (USA) (Disc {$disc}).cue", "FILE \"Final Fantasy VII (USA) (Disc {$disc}).bin\" BINARY\n");
        put("psx/Final Fantasy VII (USA) (Disc {$disc}).bin");
    }

    $result = scan('psx');
    $game = Game::sole();

    // Two sheets walked one at a time were two games and two lookups.
    expect($result->gamesCreated)->toBe(1)
        ->and($game->title)->not->toContain('Disc')
        ->and($game->files()->where('role', FileRole::Sheet)->orderBy('disc_number')->pluck('disc_number')->all())->toBe([1, 2])
        ->and($game->files()->where('role', FileRole::Track)->orderBy('disc_number')->pluck('disc_number')->all())->toBe([1, 2])
        ->and($game->identifiableFile()->filename)->toBe('Final Fantasy VII (USA) (Disc 1).bin');

    $track = GameFile::where('filename', 'Final Fantasy VII (USA) (Disc 2).bin')->sole();

    expect($track->parent->filename)->toBe('Final Fantasy VII (USA) (Disc 2).cue');
});

it('makes one game of single-file discs of one set, numbered by name', function () {
    put('psx/Metal Gear Solid (Europe) (Disc 2).chd');
    put('psx/Metal Gear Solid (Europe) (Disc 1).chd');

    scan('psx');

    $game = Game::sole();

    expect($game->files()->orderBy('disc_number')->pluck('filename')->all())
        ->toBe(['Metal Gear Solid (Europe) (Disc 1).chd', 'Metal Gear Solid (Europe) (Disc 2).chd'])
        ->and($game->files()->pluck('role')->unique()->all())->toBe([FileRole::Rom]);
});

it('keeps discs apart that differ in folder, kind or anything but the disc', function () {
    put('psx/Game (Disc 1).chd');
    put('psx/Other/Game (Disc 2).chd');
    put('psx/Riven (Disc 1).chd');
    put('psx/Riven (Disc 2).iso');
    put('psx/Myst (Disc 1).chd');
    put('psx/Myst (Disc 2) (Rev 1).chd');

    $result = scan('psx');

    expect($result->gamesCreated)->toBe(6)
        ->and(GameFile::whereNotNull('disc_number')->count())->toBe(0);
});

it('does not read a console name for a disc number', function () {
    put('amigacd32/Amiga CD32 Demo.iso');
    put('amigacd32/Amiga CD32 Sampler.iso');

    expect(scan('amigacd32')->gamesCreated)->toBe(2);
});

it('brings a set\'s discs home to the game the provider knows on a rescan', function () {
    put('psx/Riven (Disc 1).chd');
    put('psx/Riven (Disc 2).chd');

    // As an earlier scan left them: a game per disc, the second one identified.
    $first = Game::create(['console' => 'psx', 'title' => 'Riven (Disc 1)', 'slug' => 'riven-disc-1', 'status' => GameStatus::Unmatched]);
    $known = Game::create(['console' => 'psx', 'title' => 'Riven', 'slug' => 'riven', 'status' => GameStatus::Matched, 'screenscraper_id' => 1]);
    GameFile::create(['game_id' => $first->id, 'path' => 'psx/Riven (Disc 1).chd', 'filename' => 'Riven (Disc 1).chd', 'extension' => 'chd', 'role' => FileRole::Rom]);
    GameFile::create(['game_id' => $known->id, 'path' => 'psx/Riven (Disc 2).chd', 'filename' => 'Riven (Disc 2).chd', 'extension' => 'chd', 'role' => FileRole::Rom, 'disc_number' => 2]);

    scan('psx');

    expect($known->files()->orderBy('disc_number')->pluck('disc_number')->all())->toBe([1, 2])
        ->and($first->files()->count())->toBe(0);
});

it('reads an unquoted FILE directive', function () {
    put('psx/Game.cue', "FILE track01.bin BINARY\n");
    put('psx/track01.bin');

    scan('psx');

    expect(GameFile::where('role', FileRole::Track)->sole()->filename)->toBe('track01.bin');
});

it('ignores a file the console does not play', function () {
    put('snes/Super Mario World.sfc');
    put('snes/notes.txt');
    put('snes/cover.png');

    $result = scan('snes');

    expect($result->filesCreated)->toBe(1)
        ->and($result->filesSkipped)->toBe(2);
});

it('honours the console exclude list', function () {
    // ps2.php excludes games.bin, which OPL writes into the folder.
    put('ps2/games.bin');
    put('ps2/Gran Turismo 4.iso');

    $result = scan('ps2');

    expect($result->filesCreated)->toBe(1)
        ->and(GameFile::sole()->filename)->toBe('Gran Turismo 4.iso');
});

it('keeps a bios file out of the library when the console does not play it', function () {
    // snes lists rom as a bios extension and not as a file extension.
    put('snes/Super Mario World.sfc');
    put('snes/bios.rom');

    scan('snes');

    expect(GameFile::count())->toBe(1);
});

it('treats a .bin as a game on the one console that plays them', function () {
    // ps2 lists bin in file_extensions as well as bios_extensions; a game the
    // user owns outranks a firmware image the library does not track.
    put('ps2/Some Game.bin');

    $result = scan('ps2');

    expect($result->filesCreated)->toBe(1)
        ->and(GameFile::sole()->role)->toBe(FileRole::Rom);
});

it('does not duplicate anything when scanned twice', function () {
    put('snes/Super Mario World.sfc');
    put('snes/Zelda.sfc');

    scan('snes');
    $second = scan('snes');

    expect(Game::count())->toBe(2)
        ->and(GameFile::count())->toBe(2)
        ->and($second->gamesCreated)->toBe(0)
        ->and($second->filesCreated)->toBe(0)
        ->and($second->filesSeen)->toBe(2);
});

it('separates two regions of one title rather than failing on the slug', function () {
    // Both reduce to the same slug, and the unique index would reject the
    // second. The matcher merges them later; the scan has to write both first.
    put('snes/Aladdin (USA).sfc');
    put('snes/Aladdin (Europe).sfc');

    $result = scan('snes');

    expect($result->gamesCreated)->toBe(2)
        ->and(Game::pluck('slug')->sort()->values()->all())->toBe(['aladdin-europe', 'aladdin-usa']);
});

it('marks a vanished file missing instead of deleting it', function () {
    put('snes/Super Mario World.sfc');
    put('snes/Zelda.sfc');
    scan('snes');

    File::delete($this->root.'/snes/Zelda.sfc');
    $result = scan('snes');

    // The row survives, so identification is not thrown away with it.
    expect($result->filesMissing)->toBe(1)
        ->and(GameFile::count())->toBe(2)
        ->and(GameFile::missing()->sole()->filename)->toBe('Zelda.sfc')
        ->and(GameFile::present()->sole()->filename)->toBe('Super Mario World.sfc');
});

it('clears the missing mark when a file comes back', function () {
    put('snes/Super Mario World.sfc');
    put('snes/Zelda.sfc');
    scan('snes');

    File::delete($this->root.'/snes/Zelda.sfc');
    scan('snes');

    put('snes/Zelda.sfc');
    $result = scan('snes');

    expect($result->filesReturned)->toBe(1)
        ->and(GameFile::missing()->count())->toBe(0);
});

it('refuses to scan an empty folder rather than condemning the library', function () {
    put('snes/Super Mario World.sfc');
    scan('snes');

    // An unmounted disk looks exactly like this. Marking everything missing
    // would mean identifying the whole console again when it returns.
    File::delete($this->root.'/snes/Super Mario World.sfc');

    expect(fn () => scan('snes'))->toThrow(ScanAborted::class);
    expect(GameFile::missing()->count())->toBe(0);
});

it('refuses to scan a folder that is not there', function () {
    expect(fn () => scan('snes'))->toThrow(ScanAborted::class);
});

it('scans an overridden folder when convention does not apply', function () {
    put('roms/playstation/Tekken 3.iso');
    ConsoleSourceFolder::create(['console' => 'psx', 'path' => 'roms/playstation']);

    $result = scan('psx');

    expect($result->filesCreated)->toBe(1)
        ->and(GameFile::sole()->path)->toBe('roms/playstation/Tekken 3.iso');
});

it('cannot be walked out of its own folder by a crafted playlist', function () {
    put('snes/Super Mario World.sfc');
    put('psx/evil.m3u', "../../etc/passwd\n../snes/Super Mario World.sfc\n");
    put('psx/real.iso');

    scan('psx');

    // Only what the scan itself collected under psx/ can be referenced, so
    // neither entry resolves.
    expect(GameFile::where('path', 'like', 'snes/%')->count())->toBe(0)
        ->and(GameFile::pluck('path')->all())->not->toContain('../../etc/passwd');
});

it('leaves every file on disk untouched', function () {
    $path = put('snes/Super Mario World.sfc', 'original contents');
    $before = ['mtime' => filemtime($path), 'hash' => md5_file($path)];

    scan('snes');
    clearstatcache();

    expect(md5_file($path))->toBe($before['hash'])
        ->and(filemtime($path))->toBe($before['mtime'])
        ->and(File::exists($path))->toBeTrue();
});

it('queues a lookup for everything the scan found', function () {
    // Recording what is on disk is only half the job. Without this the library
    // fills with placeholders named after their filenames and nothing ever
    // identifies them.
    put('snes/Super Mario World.sfc');
    put('snes/Zelda.sfc');

    runScanJob('snes');

    Queue::assertPushed(MatchGame::class, 2);
});

it('asks about a multi-disc set once, not once per file', function () {
    put('psx/FF9/Final Fantasy IX.m3u', "FF9 (Disc 1).cue\nFF9 (Disc 2).cue\n");
    foreach (range(1, 2) as $disc) {
        put("psx/FF9/FF9 (Disc {$disc}).cue", "FILE \"FF9 (Disc {$disc}).bin\" BINARY\n");
        put("psx/FF9/FF9 (Disc {$disc}).bin");
    }

    runScanJob('psx');

    // Five files, one game, one question.
    expect(Game::count())->toBe(1);
    Queue::assertPushed(MatchGame::class, 1);
});

it('does not ask again about a game the provider already failed to name', function () {
    put('snes/Super Mario World.sfc');
    put('snes/rom_hack_final.sfc');
    scan('snes');

    Game::where('slug', 'rom-hack-final')->update(['status' => GameStatus::Unmatched]);

    runScanJob('snes');

    // Retrying it would spend the failed-lookup allowance, which is ten times
    // scarcer than the ordinary one, to be told the same thing.
    Queue::assertPushed(MatchGame::class, 1);
});

it('leaves an identified game its files on the next scan', function () {
    put('psx/FF9/FF9.m3u', "FF9 (Disc 1).cue\nFF9 (Disc 2).cue\n");

    foreach ([1, 2] as $disc) {
        put("psx/FF9/FF9 (Disc {$disc}).cue", "FILE \"FF9 (Disc {$disc}).bin\" BINARY\n");
        put("psx/FF9/FF9 (Disc {$disc}).bin");
    }

    put('psx/Crash Bandicoot (USA).bin');
    scan();

    // Identified: the provider's title and slug, no longer the filename's.
    GameFile::where('path', 'psx/FF9/FF9.m3u')->sole()->game->update(['status' => GameStatus::Matched, 'title' => 'Final Fantasy IX', 'slug' => 'final-fantasy-ix']);
    GameFile::where('path', 'psx/Crash Bandicoot (USA).bin')->sole()->game->update(['status' => GameStatus::Matched, 'title' => 'Crash Bandicoot Ⅰ', 'slug' => 'crash-bandicoot-i']);

    runScanJob('psx');

    // No placeholder made from the filenames, no file taken from its game, and
    // nothing sent back through the lookup to be merged home again.
    expect(Game::count())->toBe(2)
        ->and(Game::where('slug', 'final-fantasy-ix')->sole()->files()->count())->toBe(5)
        ->and(Game::where('slug', 'crash-bandicoot-i')->sole()->files()->count())->toBe(1);
    Queue::assertNotPushed(MatchGame::class);
});

it('queues nothing when the scan refuses to run', function () {
    Bus::fake();

    // No folder at all: the scan aborts rather than condemning the library.
    (new ScanConsoleFolder('snes'))->handle(app(LibraryScanner::class));

    Bus::assertNotDispatched(MatchGame::class);
});

it('brings an archived file back when the same file returns, and makes a new row for another', function () {
    put('snes/Mario.sfc', 'same');
    put('snes/Zelda.sfc', 'same');
    scan('snes');

    $mario = GameFile::query()->where('filename', 'Mario.sfc')->sole();
    $mario->update(['md5' => str_repeat('a', 32)]);
    $mario->archive(FileArchiveReason::Deleted);
    $zelda = GameFile::query()->where('filename', 'Zelda.sfc')->sole();
    $zelda->archive(FileArchiveReason::Deleted);
    put('snes/Zelda.sfc', 'another file');

    scan('snes');

    $back = GameFile::query()->where('filename', 'Mario.sfc')->sole();

    expect($back->id)->toBe($mario->id)
        ->and($back->md5)->toBe(str_repeat('a', 32))
        ->and($back->archived_reason)->toBeNull()
        ->and(GameFile::query()->where('filename', 'Zelda.sfc')->sole()->id)->not->toBe($zelda->id)
        ->and(GameFile::onlyTrashed()->pluck('id')->all())->toBe([$zelda->id]);
});
