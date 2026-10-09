<?php

declare(strict_types=1);

namespace App\Tools\ConsoleTool;

use App\Decryption\DiscKeys;
use App\Decryption\Ps3Disc;
use App\Models\GameFile;
use App\Tools\ConsoleTools;

/**
 * The PS3's toolbox: what a disc image says about itself.
 *
 * Read in PHP rather than by a script under app/Scripts, because the same
 * reading is what Tools → Decrypt checks a key against and what a decrypted
 * image is confirmed by ({@see Ps3Disc}); one reader serves all three. It
 * writes nothing back — decrypting is a conversion, with the queue, staging
 * and verification those already have.
 */
final class PS3 extends ConsoleTools
{
    /**
     * The disc's title ID, whether it is still encrypted, and its key when
     * one beside it fits — so a .dkey dropped in over the share is on record.
     *
     * @return array{license_id?: string, encrypted?: bool, disc_key?: string}
     */
    public function inspect(GameFile $file): array
    {
        $this->file = $file;
        $disc = Ps3Disc::open($this->absolutePath());

        if ($disc === null) {
            return [];
        }

        $titleId = $disc->titleId();
        $encrypted = $disc->encrypted();
        $keys = app(DiscKeys::class);
        $key = $encrypted === true ? $keys->onDisk($file) : null;

        return [
            ...($titleId !== null ? ['license_id' => $titleId] : []),
            ...($encrypted !== null ? ['encrypted' => $encrypted] : []),
            ...($key !== null && $keys->fits($file, $key) ? ['disc_key' => $key] : []),
        ];
    }

    /** Nothing to write into the console's folder. */
    public function canExport(): bool
    {
        return false;
    }
}
