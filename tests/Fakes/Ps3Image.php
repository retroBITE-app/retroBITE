<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Enums\FileRole;
use App\Models\Game;
use App\Models\GameFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * A PS3 disc image small enough to write in a test, laid out the way a Redump
 * dump is: a region table in sector 0, an ISO9660 tree whose PARAM.SFO sits in
 * the plain range, and an EBOOT.BIN in the one encrypted range — AES-128-CBC
 * with the disc key, the sector number as the IV, as ps3dec undoes it.
 */
final class Ps3Image
{
    /** A key for tests: any 32 hex digits will do. */
    public const KEY = '00112233445566778899AABBCCDDEEFF';

    private const SECTOR = 2048;

    private const SECTORS = 40;

    /** The encrypted range: EBOOT.BIN's two sectors. */
    private const ENCRYPTED_FIRST = 32;

    private const ENCRYPTED_LAST = 33;

    /**
     * Write one, encrypted with $key or left decrypted, and return its path.
     */
    public static function write(string $path, ?string $key = self::KEY, string $titleId = 'BLUS30538', bool $eboot = true): string
    {
        $image = str_repeat("\0", self::SECTORS * self::SECTOR);

        // Sector 0: two plain ranges around the encrypted one, as sector numbers.
        $image = self::put($image, 0, pack('NNNNNN', 2, 0, 0, self::ENCRYPTED_FIRST - 1, self::ENCRYPTED_LAST + 1, self::SECTORS - 1));

        // Sector 16: the primary volume descriptor, root directory at sector 20.
        $image = self::put($image, 16 * self::SECTOR, "\x01CD001\x01");
        $image = self::put($image, 16 * self::SECTOR + 156, self::record(20, self::SECTOR, "\0", true));

        $image = self::put($image, 20 * self::SECTOR, self::record(20, self::SECTOR, "\0", true).self::record(20, self::SECTOR, "\1", true)
            .self::record(21, self::SECTOR, 'PS3_GAME', true));

        $sfo = self::paramSfo($titleId);
        $usrdir = $eboot ? self::record(self::ENCRYPTED_FIRST, 2 * self::SECTOR, 'EBOOT.BIN;1', false) : '';

        $image = self::put($image, 21 * self::SECTOR, self::record(21, self::SECTOR, "\0", true).self::record(20, self::SECTOR, "\1", true)
            .self::record(22, strlen($sfo), 'PARAM.SFO;1', false).self::record(23, self::SECTOR, 'USRDIR', true));
        $image = self::put($image, 22 * self::SECTOR, $sfo);
        $image = self::put($image, 23 * self::SECTOR, self::record(23, self::SECTOR, "\0", true).self::record(21, self::SECTOR, "\1", true).$usrdir);

        // EBOOT.BIN: a signed executable's magic, then something to decrypt.
        for ($sector = self::ENCRYPTED_FIRST; $sector <= self::ENCRYPTED_LAST; $sector++) {
            $plain = ($sector === self::ENCRYPTED_FIRST ? "SCE\0" : '').str_repeat(chr($sector), self::SECTOR);
            $plain = substr($plain, 0, self::SECTOR);

            $image = self::put($image, $sector * self::SECTOR, $key === null ? $plain : self::encrypt($plain, $key, $sector));
        }

        file_put_contents($path, $image);

        return $path;
    }

    /**
     * A PS3 game of one image in a test's library: the image (decrypted when $encrypted
     * is false), its .dkey when $key, and the rows the scanner and toolbox would leave.
     *
     * @param  string  $root  the test's library root
     * @param  array<string, mixed>  $file  more columns for the file row
     * @param  array<string, mixed>  $game  more columns for the game row
     * @param  array{license_id?: string|null, video_mode?: string|null, disc_key?: string|null}  $meta  more disc facts
     */
    public static function fileRow(string $root, string $name, ?bool $encrypted = true, bool $key = true, array $file = [], array $game = [], array $meta = []): GameFile
    {
        $path = self::write($root.'/ps3/'.$name, $encrypted === false ? null : self::KEY);

        if ($key) {
            File::put(Str::beforeLast($path, '.').'.dkey', self::KEY."\n");
        }

        return GameFile::factory()
            ->for(Game::factory()->forConsole('ps3')->create(['title' => Str::beforeLast($name, ' ('), ...$game]))
            ->withMeta(['encrypted' => $encrypted, ...$meta])
            ->create([
                'path' => 'ps3/'.$name,
                'filename' => $name,
                'extension' => 'iso',
                'size_bytes' => filesize($path),
                'role' => FileRole::Rom,
                ...$file,
            ]);
    }

    /** One ISO9660 directory record, each number stored both ways round. */
    private static function record(int $lba, int $size, string $id, bool $directory): string
    {
        $length = 33 + strlen($id);
        $length += $length % 2;

        return pack('CCVNVNx7CCCvnC', $length, 0, $lba, $lba, $size, $size, $directory ? 2 : 0, 0, 0, 1, 1, strlen($id))
            .$id.str_repeat("\0", $length - 33 - strlen($id));
    }

    /** A PARAM.SFO holding a TITLE_ID and nothing else. */
    private static function paramSfo(string $titleId): string
    {
        $keys = "TITLE_ID\0\0\0\0";
        $value = str_pad($titleId."\0", 16, "\0");
        $keysAt = 20 + 16;

        return "\0PSF".pack('VVVV', 0x0101, $keysAt, $keysAt + strlen($keys), 1)
            .pack('vvVVV', 0, 0x0204, strlen($titleId) + 1, 16, 0)
            .$keys.$value;
    }

    /** One sector encrypted the way a Redump dump is: AES-128-CBC, the sector number as IV. */
    private static function encrypt(string $plain, string $key, int $sector): string
    {
        $iv = str_repeat("\0", 12).pack('N', $sector);

        return (string) openssl_encrypt($plain, 'aes-128-cbc', (string) hex2bin($key), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
    }

    /** The image with bytes written over it at an offset. */
    private static function put(string $image, int $offset, string $bytes): string
    {
        return substr_replace($image, $bytes, $offset, strlen($bytes));
    }
}
