<?php

use App\Enums\FileRole;
use App\Jobs\MatchGame;
use App\Jobs\ScanConsoleFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Support\Scanning\ScanPlan;
use App\Support\Scanning\ScanUnit;
use Illuminate\Support\Facades\DB;
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

function rom(string $relative, string $contents = 'x'): void
{
    $path = test()->root.'/'.$relative;
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);
}

it('records a large console in chunks of a batch', function () {
    foreach (range(1, ScanConsoleFolder::CHUNK + 20) as $n) {
        rom("snes/Game {$n}.sfc");
    }

    runScanJob('snes');

    $batch = DB::table('job_batches')->sole();

    // Two chunks for a hundred and twenty games, every one recorded.
    expect($batch->name)->toBe('Scan snes')
        ->and($batch->total_jobs)->toBe(2)
        ->and($batch->pending_jobs)->toBe(0)
        ->and($batch->failed_jobs)->toBe(0)
        ->and($batch->finished_at)->not->toBeNull()
        ->and(Game::count())->toBe(120);

    Queue::assertPushed(MatchGame::class, 120);
});

it('keeps a multi-disc set whole when the plan is cut into chunks', function () {
    rom('psx/FF9/Final Fantasy IX.m3u', "FF9 (Disc 1).cue\nFF9 (Disc 2).cue\n");
    foreach (range(1, 2) as $disc) {
        rom("psx/FF9/FF9 (Disc {$disc}).cue", "FILE \"FF9 (Disc {$disc}).bin\" BINARY\n");
        rom("psx/FF9/FF9 (Disc {$disc}).bin");
    }

    runScanJob('psx');

    $playlist = GameFile::where('role', FileRole::Playlist)->sole();

    expect(Game::count())->toBe(1)
        ->and(GameFile::count())->toBe(5)
        ->and(GameFile::where('parent_id', $playlist->id)->count())->toBe(2)
        ->and(GameFile::where('role', FileRole::Track)->whereNotNull('parent_id')->count())->toBe(2);
});

it('marks what has gone before the chunks run', function () {
    rom('snes/Mario.sfc');
    rom('snes/Zelda.sfc');
    runScanJob('snes');

    File::delete($this->root.'/snes/Zelda.sfc');
    runScanJob('snes');

    expect(GameFile::missing()->sole()->filename)->toBe('Zelda.sfc');
});

it('does not start a second scan of a console while one is running', function () {
    rom('snes/Mario.sfc');

    DB::table('job_batches')->insert([
        'id' => (string) Str::uuid(),
        'name' => 'Scan snes',
        'total_jobs' => 1,
        'pending_jobs' => 1,
        'failed_jobs' => 0,
        'failed_job_ids' => '[]',
        'created_at' => now()->getTimestamp(),
    ]);

    runScanJob('snes');

    expect(GameFile::count())->toBe(0);
});

it('keeps titles that share a slug in one chunk', function () {
    $unit = fn (string $title): ScanUnit => new ScanUnit($title, []);

    $plan = new ScanPlan([$unit('Alpha'), $unit('Bravo'), $unit('bravo'), $unit('Charlie')], 0);

    $chunks = array_map(
        fn (array $chunk): array => array_map(fn (ScanUnit $unit): string => $unit->title, $chunk),
        $plan->chunks(2),
    );

    expect($chunks)->toBe([['Alpha'], ['Bravo', 'bravo'], ['Charlie']]);
});
