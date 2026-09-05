<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Game;
use App\Repositories\GameRepository;
use App\Support\Console;

/**
 * Owns the two halves of a scan: indexing the directory, which is cheap, and
 * hashing what it found, which is not.
 *
 * They are separate because md5-ing a shelf of PS2 ISOs runs for minutes and a
 * single request may not — the whole scan used to die on PHP's 30-second cap.
 * Indexing lands every file in the library immediately; hashing then runs in
 * time-boxed batches the caller repeats until nothing is left. Only
 * identification needs the digest, so the games are browsable meanwhile.
 */
class LibraryScanService
{
    /** Seconds one hashing batch may spend before returning for another call. */
    public const BATCH_SECONDS = 20.0;

    /** Rows pulled per batch — an upper bound for many tiny files, not a target. */
    private const BATCH_ROWS = 64;

    public function __construct(
        private FilesystemService $filesystem,
        private GameRepository $games,
    ) {}

    /**
     * Index the console directory and drop every row the walk did not yield, so
     * the database matches the disk. No file is read, only stat-ed.
     *
     * Pruning against what was scanned, rather than against what still exists on
     * disk, is what lets `exclude_files` and a narrowed extension list take
     * effect on files that were already in the library.
     *
     * @return array{found: int, pruned: int, remaining: int}
     */
    public function index(Console $console): array
    {
        // An absent directory yields nothing, and pruning on that would empty the
        // console's library the first time a share failed to mount.
        $mounted = is_dir($console->path());
        $scanned = [];

        foreach ($this->filesystem->scanConsoleDir($console) as $file) {
            $scanned[] = $this->games->scanUpsert($console->key, $file);
        }

        return [
            'found'     => count($scanned),
            'pruned'    => $mounted ? $this->games->pruneExcept($console, $scanned) : 0,
            'remaining' => $this->games->awaitingHashCount($console),
        ];
    }

    /**
     * Hash unhashed games until the time budget is spent, then report what is
     * left so the caller knows whether to come back.
     *
     * At least one file is always hashed, even when it alone overruns the
     * budget — otherwise a library whose smallest ISO takes longer than a batch
     * would never advance. A batch nothing could be read from ends the pass
     * rather than being re-queried forever.
     *
     * @param float $budgetSeconds Wall-clock allowance; 0 hashes everything.
     * @return array{hashed: int, remaining: int}
     */
    public function hashPending(Console $console, float $budgetSeconds = self::BATCH_SECONDS): array
    {
        // A single ISO can outlive the default 30s cap on its own, and this is
        // the one request that legitimately reads gigabytes off disk.
        set_time_limit(0);

        $started = microtime(true);
        $hashed  = 0;

        while (($batch = $this->games->awaitingHash($console, self::BATCH_ROWS))->isNotEmpty()) {
            $progressed = false;

            foreach ($batch as $game) {
                if ($this->hash($game)) {
                    $hashed++;
                    $progressed = true;
                }

                if ($budgetSeconds > 0.0 && (microtime(true) - $started) >= $budgetSeconds) {
                    break 2;
                }
            }

            if (!$progressed) {
                break;
            }
        }

        return [
            'hashed'    => $hashed,
            'remaining' => $this->games->awaitingHashCount($console),
        ];
    }

    /**
     * Digest one game. Returns false when the file could not be read, which
     * leaves the row pending for the next scan rather than inventing a hash.
     */
    private function hash(Game $game): bool
    {
        $path = (string) $game->file_path;
        $md5  = is_file($path) ? md5_file($path) : false;

        if ($md5 === false) {
            logger()->warning('Could not hash game', ['id' => $game->id]);

            return false;
        }

        $this->games->storeMd5((string) $game->id, $md5);

        return true;
    }
}
