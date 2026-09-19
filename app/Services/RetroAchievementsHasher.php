<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\RetroAchievements\HasherUnavailable;
use App\Exceptions\RetroAchievements\HashFailed;
use Illuminate\Support\Facades\Process;

/**
 * Runs RAHasher over one file.
 *
 * The hash RetroAchievements identifies a game by is not an MD5 of the file.
 * It is console-specific: the iNES header comes off a NES rom, copier headers
 * come off a SNES one, and for a disc the primary executable named in
 * SYSTEM.CNF is hashed rather than the disc. Reimplementing that would be
 * reimplementing rcheevos, so this shells out to the tool that already has it.
 *
 * Through Laravel's Process facade, which is Symfony's underneath but can be
 * faked — and, more to the point, refused: the test suite forbids stray
 * processes the same way it forbids stray HTTP, so a fake whose pattern does
 * not match fails loudly instead of running the real hasher over a real CHD.
 */
class RetroAchievementsHasher
{
    /** What RAHasher prints instead of a hash when it cannot read a file. */
    private const FAILURE_MARKER = '????????????????????????????????';

    /**
     * Hash one file as one console.
     *
     * @param  int  $consoleId  RetroAchievements' ConsoleID, which is also RAHasher's systemid
     *
     * @throws HasherUnavailable when the binary is missing or not runnable
     * @throws HashFailed when the binary ran and could not hash this file
     */
    public function hash(int $consoleId, string $absolutePath): string
    {
        $binary = (string) config('retroachievements.hasher_path', 'RAHasher');

        $result = Process::timeout((int) config('retroachievements.hasher_timeout', 1800))
            ->run([$binary, (string) $consoleId, $absolutePath]);

        $output = trim($result->output());

        if ($result->failed()) {
            // 126 and 127 are the shell's own: not executable, and not found.
            // Distinguishing them matters because a missing binary says nothing
            // about the file, and must not mark the game as unhashable.
            if (in_array($result->exitCode(), [126, 127], true)) {
                throw new HasherUnavailable(
                    "RAHasher could not be run ({$binary}). Set RA_HASHER_PATH or rebuild the image.",
                    (int) $result->exitCode(),
                );
            }

            throw new HashFailed(
                'RAHasher could not hash the file.',
                (int) $result->exitCode(),
                trim($result->errorOutput()) ?: $output,
            );
        }

        // A zero exit with nothing on stdout is the other shape a missing
        // binary can take, depending on how the process was resolved.
        if ($output === '') {
            throw new HasherUnavailable("RAHasher produced no output ({$binary}).");
        }

        // Multi-file mode prints "<hash> <filename>"; single-file mode prints
        // the hash alone. Taking the first token handles both.
        $hash = strtolower((string) strtok($output, " \t\n"));

        if ($hash === self::FAILURE_MARKER) {
            throw new HashFailed('RAHasher reported an unreadable file.', 0, $output);
        }

        if (preg_match('/^[0-9a-f]{32}$/', $hash) !== 1) {
            throw new HashFailed('RAHasher returned something that is not a hash.', 0, $output);
        }

        return $hash;
    }
}
