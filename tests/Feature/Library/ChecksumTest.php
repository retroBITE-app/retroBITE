<?php

use App\Enums\FileRole;
use App\Jobs\HashFile;
use App\Jobs\MatchGame;
use App\Models\Game;
use App\Models\GameFile;
use App\Services\GameMatcher;
use App\Support\Scanning\Checksums;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retrobite-hash-'.Str::random(8);
    File::ensureDirectoryExists($this->root.'/psx');
    config()->set('settings.games_path', $this->root);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('computes all three checksums in a single read', function () {
    $contents = random_bytes(3_000_000);
    $path = $this->root.'/psx/game.bin';
    File::put($path, $contents);

    $sums = Checksums::of($path);

    // Larger than the 1 MB chunk, so this also proves the streaming is right
    // rather than only working on data that fits in one buffer.
    expect($sums->md5)->toBe(md5($contents))
        ->and($sums->sha1)->toBe(sha1($contents))
        ->and($sums->crc)->toBe(strtoupper(hash('crc32b', $contents)))
        ->and($sums->size)->toBe(3_000_000);
});

it('records the size from the same read as the hashes', function () {
    $path = $this->root.'/psx/game.bin';
    File::put($path, str_repeat('a', 1024));

    $game = Game::factory()->forConsole('psx')->create();
    $file = GameFile::factory()->for($game)->create([
        'path' => 'psx/game.bin', 'filename' => 'game.bin', 'extension' => 'bin',
        // Deliberately wrong, as a stale scan would have left it.
        'size_bytes' => 999,
    ]);

    (new HashFile($file->id))->handle();

    $file->refresh();

    expect($file->md5)->toBe(md5(str_repeat('a', 1024)))
        ->and($file->size_bytes)->toBe(1024)
        ->and($file->hashed_at)->not->toBeNull()
        ->and($file->isHashed())->toBeTrue();
});

it('does not fail when the file has gone since the scan', function () {
    $game = Game::factory()->forConsole('psx')->create();
    $file = GameFile::factory()->for($game)->create(['path' => 'psx/vanished.bin']);

    (new HashFile($file->id))->handle();

    expect($file->refresh()->md5)->toBeNull();
});

it('chains hashing before a second lookup when name and size miss', function () {
    Bus::fake();
    Http::fake(['*' => Http::response(
        mb_convert_encoding('Erreur : Jeu non trouvée !', 'ISO-8859-1', 'UTF-8'), 404
    )]);

    $game = Game::factory()->forConsole('psx')->create();
    GameFile::factory()->for($game)->create([
        'path' => 'psx/game.bin', 'filename' => 'game.bin', 'extension' => 'bin', 'role' => FileRole::Track,
    ]);

    (new MatchGame($game->id))->handle(app(GameMatcher::class));

    // Hashing runs on its own queue so the single scraper worker is not held
    // for the minutes a disc image takes to read.
    Bus::assertChained([HashFile::class, MatchGame::class]);
});

it('puts hashing on the long connection and lookups on the scraper queue', function () {
    // It was on `media` until the connection split. The jobs table has no
    // connection column, so a job is re-reserved after the retry_after of
    // whichever connection's worker popped it — and the media workers run on
    // the 90-second one while this job has an hour-long timeout. Isolation is
    // by queue name, which is why the name had to change too.
    expect((new HashFile(1))->queue)->toBe('hash')
        ->and((new HashFile(1))->connection)->toBe('database-long')
        ->and((new MatchGame(1))->queue)->toBe('scraper');
});
