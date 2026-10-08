<?php

declare(strict_types=1);

namespace App\Decryption;

use App\Conversion\Converter;
use App\Conversion\Disc;
use App\Conversion\SourceSet;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * A Redump PS3 image decrypted with its disc key: ps3dec. The decrypted image
 * takes the encrypted one's place under its name, and the key beside it goes
 * too — ps3dec keeps the region table, so a key left there would have
 * ps3netsrv decrypt the image a second time.
 *
 * Offered only for an image the toolbox has read as encrypted and that has a
 * key beside it, and only on Tools → Decrypt. A wrong key makes ps3dec write
 * noise and exit 0, so the image is confirmed decrypted before anything is
 * swapped; the key itself was checked against the disc when it was saved.
 */
final class Ps3Decrypt extends Converter
{
    public function __construct(private readonly DiscKeys $keys) {}

    public function key(): string
    {
        return 'ps3-decrypt';
    }

    public function label(): string
    {
        return __('Decrypted ISO');
    }

    public function description(): string
    {
        return __('ps3dec — plays on webMAN MOD and multiMAN without a key');
    }

    public function tool(): string
    {
        return 'ps3dec';
    }

    /** @return list<string> */
    public function from(): array
    {
        return ['iso'];
    }

    public function to(): string
    {
        return 'iso';
    }

    /** Nothing to choose: the source is always replaced, and confirm() always runs. */
    public function options(): array
    {
        return [];
    }

    /** One image, read as encrypted, with its key beside it. */
    public function supports(SourceSet $set): bool
    {
        if ($set->isSet() || $set->file->meta?->encrypted !== true) {
            return false;
        }

        return $this->keys->onDisk($set->file) !== null;
    }

    /**
     * @param  list<string>  $outputs
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public function arguments(string $input, array $outputs, array $options): array
    {
        $output = (string) Arr::first($outputs);

        return [
            $input,
            '--dk', (string) $this->keys->read($input),
            '--skip',
            '-o', dirname($output),
            '-n', Str::beforeLast(basename($output), '.'),
        ];
    }

    /** A wrong key makes ps3dec write noise and exit 0: the image has to read as decrypted. */
    public function confirm(string $output): ?string
    {
        if (Ps3Disc::open($output)?->encrypted() === false) {
            return null;
        }

        return __('The written image still reads as encrypted: the key does not fit this disc.');
    }

    /** ps3dec writes a log/ folder into wherever it is run. */
    public function workingDirectory(string $staging): string
    {
        return $staging;
    }

    /** The decrypted image is the game, under the same name: two copies would be 16 GB of one disc. */
    public function replacesSource(): bool
    {
        return true;
    }

    /**
     * The key files beside the image: left behind, ps3netsrv would decrypt the decrypted image again.
     *
     * @return list<string>
     */
    public function companions(Disc $disc, string $root): array
    {
        return array_values(collect($this->keys->keyFiles($root.'/'.$disc->file->path))
            ->map(function (string $file) use ($root): string {
                return Str::after($file, $root.'/');
            })
            ->all());
    }

    public function page(): string
    {
        return self::PAGE_DECRYPT;
    }
}
