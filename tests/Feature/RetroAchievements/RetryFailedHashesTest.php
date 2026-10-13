<?php

use App\Enums\FileRole;
use App\Enums\RetroAchievementsStatus;
use App\Jobs\RetroAchievements\IdentifyGame;
use App\Models\AppSetting;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Services\RetroAchievementsHasher;
use App\Support\Console;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * RA hashes that find themselves: a file a scan has just found is hashed
 * without anybody asking, and a game RAHasher could not hash is tried again
 * when RAHasher changes — never by hand, and never the same failing file over
 * and over.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-rehash-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/ps2');
    config()->set('settings.games_path', $this->root);

    // A stand-in binary: build() reads it, nothing ever runs it.
    $this->hasher = $this->root.'/RAHasher';
    File::put($this->hasher, 'build one');
    chmod($this->hasher, 0755);
    config()->set('retroachievements.hasher_path', $this->hasher);

    Queue::fake();
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

/**
 * A PS2 game with one file, its RA state as given.
 *
 * @param  array<string, mixed>  $file  more columns for the file row
 */
function ps2Game(string $name, RetroAchievementsStatus $status = RetroAchievementsStatus::Matched, array $file = []): Game
{
    $game = Game::factory()->forConsole('ps2')->matched()->create([
        'title' => $name, 'slug' => Str::slug($name), 'retroachievements_status' => $status,
    ]);

    GameFile::factory()->for($game)->create([
        'path' => 'ps2/'.$name.'.zso', 'filename' => $name.'.zso', 'extension' => 'zso', 'role' => FileRole::Rom,
        ...$file,
    ]);

    return $game;
}

/**
 * A file RAHasher read and could not hash: no hash, the fingerprint it failed on.
 *
 * @return array{ra_hash: null, ra_hash_size: int, ra_hash_mtime: int, ra_hashed_at: Carbon}
 */
function failedHash(): array
{
    return ['ra_hash' => null, 'ra_hash_size' => 6, 'ra_hash_mtime' => 1_700_000_000, 'ra_hashed_at' => now()];
}

it('queues a game whose file RAHasher has never seen', function () {
    $converted = ps2Game('Harry Potter');

    expect(IdentifyGame::queueUnhashed('ps2'))->toBe(1);

    Queue::assertPushed(IdentifyGame::class, function (IdentifyGame $job) use ($converted): bool {
        return $job->gameId === $converted->id;
    });
});

it('leaves a hashed file, and a failed one that has not changed, alone', function () {
    ps2Game('Hashed', file: ['ra_hash' => str_repeat('a', 32), 'ra_hash_size' => 6, 'ra_hash_mtime' => 1]);
    ps2Game('Failed', RetroAchievementsStatus::HashFailed, failedHash());

    expect(IdentifyGame::queueUnhashed('ps2'))->toBe(0);
    Queue::assertNothingPushed();
});

it('does not queue a game for a file RAHasher would not be given', function () {
    // The ZSO came first and is hashed; the ISO left beside it is never read,
    // so it must not bring the game back after every scan.
    $game = ps2Game('Beside', file: ['ra_hash' => str_repeat('a', 32), 'ra_hash_size' => 6, 'ra_hash_mtime' => 1]);
    GameFile::factory()->for($game)->create([
        'path' => 'ps2/Beside.iso', 'filename' => 'Beside.iso', 'extension' => 'iso', 'role' => FileRole::Rom,
    ]);

    expect(IdentifyGame::queueUnhashed('ps2'))->toBe(0);
});

it('queues nothing for a console RetroAchievements does not cover', function () {
    $game = Game::factory()->forConsole('ps4')->create();
    GameFile::factory()->for($game)->create(['path' => 'ps4/Game.pkg', 'filename' => 'Game.pkg', 'extension' => 'pkg', 'role' => FileRole::Rom]);

    expect(Console::tryFrom('ps4')?->retroachievementsId)->toBeNull()
        ->and(IdentifyGame::queueUnhashed('ps4'))->toBe(0);
});

it('hashes what a scan has just found', function () {
    // A conversion's output arrives this way: placed, then a rescan.
    File::put($this->root.'/ps2/Game.zso', 'x');
    ConsoleSourceFolder::add(new Console('ps2'));

    runScanJob('ps2');

    Queue::assertPushed(IdentifyGame::class);
});

it('retries every game it could not hash once RAHasher changes, and records the build', function () {
    $failed = ps2Game('Failed', RetroAchievementsStatus::HashFailed, failedHash());
    $matched = ps2Game('Matched', file: ['ra_hash' => str_repeat('a', 32), 'ra_hash_size' => 6, 'ra_hash_mtime' => 1]);
    $noMatch = ps2Game('Unknown', RetroAchievementsStatus::NoMatch, ['ra_hash' => str_repeat('b', 32), 'ra_hash_size' => 6, 'ra_hash_mtime' => 1]);

    // Nothing recorded yet: an install's first boot on this version.
    $this->artisan('retrobite:ra:retry-failed-hashes')->assertSuccessful();

    expect($failed->refresh()->retroachievements_status)->toBe(RetroAchievementsStatus::Pending)
        ->and($failed->files()->first()->ra_hash_size)->toBeNull()
        ->and($matched->refresh()->retroachievements_status)->toBe(RetroAchievementsStatus::Matched)
        ->and($noMatch->refresh()->retroachievements_status)->toBe(RetroAchievementsStatus::NoMatch)
        ->and(AppSetting::get(AppSetting::RA_HASHER_BUILD))->toBe(sha1_file($this->hasher));

    Queue::assertPushed(IdentifyGame::class, 1);
    Queue::assertPushed(IdentifyGame::class, function (IdentifyGame $job) use ($failed): bool {
        return $job->gameId === $failed->id;
    });
});

it('retries nothing while RAHasher is the one it was', function () {
    ps2Game('Failed', RetroAchievementsStatus::HashFailed, failedHash());
    AppSetting::put(AppSetting::RA_HASHER_BUILD, sha1_file($this->hasher));

    $this->artisan('retrobite:ra:retry-failed-hashes')->assertSuccessful();

    Queue::assertNothingPushed();
    expect(Game::first()->retroachievements_status)->toBe(RetroAchievementsStatus::HashFailed);
});

it('retries again when the image ships another build', function () {
    ps2Game('Failed', RetroAchievementsStatus::HashFailed, failedHash());
    AppSetting::put(AppSetting::RA_HASHER_BUILD, sha1_file($this->hasher));

    File::put($this->hasher, 'build two');
    $this->artisan('retrobite:ra:retry-failed-hashes')->assertSuccessful();

    Queue::assertPushed(IdentifyGame::class, 1);
    expect(AppSetting::get(AppSetting::RA_HASHER_BUILD))->toBe(sha1('build two'));
});

it('changes nothing when RAHasher is not there at all', function () {
    ps2Game('Failed', RetroAchievementsStatus::HashFailed, failedHash());
    config()->set('retroachievements.hasher_path', $this->root.'/gone');

    // A broken deployment says nothing about the files.
    $this->artisan('retrobite:ra:retry-failed-hashes')->assertSuccessful();

    Queue::assertNothingPushed();
    expect(AppSetting::get(AppSetting::RA_HASHER_BUILD))->toBeNull()
        ->and(GameFile::first()->ra_hash_size)->toBe(6);
});

it('retries when told to, whatever the build', function () {
    ps2Game('Failed', RetroAchievementsStatus::HashFailed, failedHash());
    AppSetting::put(AppSetting::RA_HASHER_BUILD, sha1_file($this->hasher));

    $this->artisan('retrobite:ra:retry-failed-hashes', ['--force' => true])->assertSuccessful();

    Queue::assertPushed(IdentifyGame::class, 1);
});

it('names the build by the binary, and has none without one', function () {
    $hasher = app(RetroAchievementsHasher::class);

    expect($hasher->build())->toBe(sha1_file($this->hasher));

    config()->set('retroachievements.hasher_path', '');
    expect($hasher->build())->toBeNull();

    config()->set('retroachievements.hasher_path', $this->root.'/gone');
    expect($hasher->build())->toBeNull();
});

it('reads a failed file again when identification is forced', function () {
    ps2Game('Failed', RetroAchievementsStatus::HashFailed, failedHash());

    $this->artisan('retrobite:ra:identify', ['--console' => 'ps2', '--force' => true])->assertSuccessful();

    // Otherwise HashGame would see the failure on record and skip the file.
    expect(GameFile::first()->ra_hash_size)->toBeNull();
});

it('forgets only the failures of the games it queues, under a limit', function () {
    $first = ps2Game('First', RetroAchievementsStatus::HashFailed, failedHash());
    $second = ps2Game('Second', RetroAchievementsStatus::HashFailed, failedHash());

    $this->artisan('retrobite:ra:identify', ['--force' => true, '--limit' => 1])->assertSuccessful();

    Queue::assertPushed(IdentifyGame::class, 1);
    $queued = Queue::pushed(IdentifyGame::class)->first()->gameId;
    $other = $queued === $first->id ? $second : $first;

    expect(GameFile::query()->where('game_id', $queued)->value('ra_hash_size'))->toBeNull()
        ->and($other->files()->first()->ra_hash_size)->toBe(6);
});

it('hashes what a scan run in the foreground has just found', function () {
    File::put($this->root.'/ps2/Game.zso', 'x');
    ConsoleSourceFolder::add(new Console('ps2'));

    $this->artisan('retrobite:scan', ['console' => 'ps2'])->assertSuccessful();

    Queue::assertPushed(IdentifyGame::class);
});
