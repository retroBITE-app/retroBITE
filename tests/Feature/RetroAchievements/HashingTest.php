<?php

use App\Enums\FileRole;
use App\Enums\RetroAchievementsStatus;
use App\Exceptions\RetroAchievements\HasherUnavailable;
use App\Exceptions\RetroAchievements\HashFailed;
use App\Jobs\RetroAchievements\HashGame;
use App\Models\Game;
use App\Models\GameFile;
use App\Services\RetroAchievementsHasher;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

const RA_HASH = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-ra-'.Str::random(8);
    mkdir($this->root.'/psx', recursive: true);
    config()->set('settings.games_path', $this->root);
    config()->set('retroachievements.hasher_path', 'RAHasher');
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->root));
});

function writeFile(string $relative, string $contents = 'x'): string
{
    $path = test()->root.'/'.$relative;
    @mkdir(dirname($path), recursive: true);
    file_put_contents($path, $contents);

    return $relative;
}

function hashedOk(): void
{
    Process::fake(['*' => Process::result(RA_HASH."\n")]);
}

it('hands RAHasher the container, not the track', function () {
    $game = Game::factory()->forConsole('psx')->create();

    GameFile::factory()->for($game)->create([
        'path' => writeFile('psx/Game.cue'), 'filename' => 'Game.cue',
        'extension' => 'cue', 'role' => FileRole::Sheet,
    ]);
    GameFile::factory()->for($game)->create([
        'path' => writeFile('psx/Game.bin'), 'filename' => 'Game.bin',
        'extension' => 'bin', 'role' => FileRole::Track,
    ]);

    // The ScreenScraper pick is the track, because that is what its database
    // holds. RAHasher needs the sheet: the executable it hashes is named in
    // SYSTEM.CNF, which it reads through the sheet's disc.
    expect($game->identifiableFile()->extension)->toBe('bin')
        ->and($game->hashableFile()->extension)->toBe('cue');
});

it('picks the first disc of a playlist', function () {
    $game = Game::factory()->forConsole('psx')->create();

    $playlist = GameFile::factory()->for($game)->create([
        'path' => writeFile('psx/Game.m3u'), 'filename' => 'Game.m3u',
        'extension' => 'm3u', 'role' => FileRole::Playlist,
    ]);
    GameFile::factory()->for($game)->create([
        'path' => writeFile('psx/Game (Disc 2).cue'), 'filename' => 'Game (Disc 2).cue',
        'extension' => 'cue', 'role' => FileRole::Sheet, 'parent_id' => $playlist->id, 'disc_number' => 2,
    ]);

    // The playlist itself: it names the discs in order, so RAHasher resolves
    // disc one from it without us guessing which file that is.
    expect($game->hashableFile()->extension)->toBe('m3u');
});

it('stores the hash with the size and mtime it was taken at', function () {
    hashedOk();

    $game = Game::factory()->forConsole('psx')->create();
    $file = GameFile::factory()->for($game)->create([
        'path' => writeFile('psx/Game.chd', 'abcdef'), 'filename' => 'Game.chd',
        'extension' => 'chd', 'role' => FileRole::Rom,
    ]);

    (new HashGame($game->id))->handle(app(RetroAchievementsHasher::class));

    expect($file->refresh()->ra_hash)->toBe(RA_HASH)
        ->and($file->ra_hash_size)->toBe(6)
        ->and($file->ra_hashed_at)->not->toBeNull();

    // The console's RetroAchievements id, not its ScreenScraper one. PSX is 57
    // at ScreenScraper and 12 here, and 12 at ScreenScraper is the Game Boy
    // Advance — so getting this wrong hashes with the wrong algorithm and
    // misses silently.
    Process::assertRan(fn ($process) => in_array('12', (array) $process->command, true));
});

it('does not hash again while the file is unchanged', function () {
    hashedOk();

    $game = Game::factory()->forConsole('psx')->create();
    $file = GameFile::factory()->for($game)->create([
        'path' => writeFile('psx/Game.chd', 'abcdef'), 'filename' => 'Game.chd',
        'extension' => 'chd', 'role' => FileRole::Rom,
    ]);

    (new HashGame($game->id))->handle(app(RetroAchievementsHasher::class));

    // Every ScreenScraper retry that ends Unmatched dispatches the RA chain
    // again. Reading a four-gigabyte image to learn what is already on the row
    // is the one cost worth never paying twice.
    (new HashGame($game->id))->handle(app(RetroAchievementsHasher::class));

    // One in total across both calls: the second found the fingerprint
    // unchanged and never reached the hasher.
    Process::assertRanTimes(fn () => true, 1);
    expect($file->refresh()->ra_hash)->toBe(RA_HASH);
});

it('hashes again when a track under the cuesheet changes', function () {
    hashedOk();

    $game = Game::factory()->forConsole('psx')->create();
    $sheet = GameFile::factory()->for($game)->create([
        'path' => writeFile('psx/Game.cue', 'FILE "Game.bin" BINARY'), 'filename' => 'Game.cue',
        'extension' => 'cue', 'role' => FileRole::Sheet,
    ]);
    GameFile::factory()->for($game)->create([
        'path' => writeFile('psx/Game.bin', 'aaaa'), 'filename' => 'Game.bin',
        'extension' => 'bin', 'role' => FileRole::Track, 'parent_id' => $sheet->id,
    ]);

    (new HashGame($game->id))->handle(app(RetroAchievementsHasher::class));

    // Swapping the track leaves the cuesheet's own size and mtime untouched,
    // so a fingerprint taken from the container alone would hand back a stale
    // hash for ever.
    file_put_contents($this->root.'/psx/Game.bin', 'bbbbbbbbbbbb');
    touch($this->root.'/psx/Game.bin', time() + 60);

    Process::fake(['*' => Process::result("ffffffffffffffffffffffffffffffff\n")]);

    (new HashGame($game->id))->handle(app(RetroAchievementsHasher::class));

    expect($sheet->refresh()->ra_hash)->toBe('ffffffffffffffffffffffffffffffff');
});

it('marks the game when RAHasher cannot read the file', function () {
    Process::fake(['*' => Process::result(output: 'Could not open file', exitCode: 1)]);

    $game = Game::factory()->forConsole('psx')->create();
    GameFile::factory()->for($game)->create([
        'path' => writeFile('psx/Game.chd'), 'filename' => 'Game.chd',
        'extension' => 'chd', 'role' => FileRole::Rom,
    ]);

    // The status is written before the failure is raised, and raised rather
    // than swallowed because there is no queue job here to fail — run from a
    // command, a silent failure reads as success.
    expect(fn () => (new HashGame($game->id))->handle(app(RetroAchievementsHasher::class)))
        ->toThrow(HashFailed::class);

    expect($game->refresh()->retroachievements_status)->toBe(RetroAchievementsStatus::HashFailed);
});

it('leaves the game alone when the binary itself is missing', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'not found', exitCode: 127)]);

    $game = Game::factory()->forConsole('psx')->create();
    GameFile::factory()->for($game)->create([
        'path' => writeFile('psx/Game.chd'), 'filename' => 'Game.chd',
        'extension' => 'chd', 'role' => FileRole::Rom,
    ]);

    expect(fn () => (new HashGame($game->id))->handle(app(RetroAchievementsHasher::class)))
        ->toThrow(HasherUnavailable::class);

    // A missing binary says nothing about the file. Marking the game would put
    // the whole library into HashFailed over one bad deployment, and getting
    // back out would take a reset of every row.
    expect($game->refresh()->retroachievements_status)->toBe(RetroAchievementsStatus::Pending);
});
