<?php

declare(strict_types=1);

namespace App\Decryption;

use App\Conversion\ConversionQueue;
use App\Conversion\Converter;
use App\Conversion\Converters;
use App\Conversion\SourceSet;
use App\Enums\DecryptState;
use App\Exceptions\LibraryPathException;
use App\Models\Conversion;
use App\Models\GameFile;
use App\Support\Console;
use App\Support\LibraryPath;
use App\Support\Scanning\LibraryFolders;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The disc keys kept beside encrypted PS3 images: `<image name>.dkey`, or the
 * older `.key`, exactly where ps3netsrv looks for them. A key there lets the
 * image play over the network as it is, and is what Tools → Decrypt hands
 * ps3dec. Only the lower-case names count, as they do to ps3netsrv.
 *
 * A key seen to fit is also kept with the file's facts (game_file_meta.disc_key):
 * decrypting deletes the file beside the image, and the key is still worth
 * showing — to hand to a friend with the same disc.
 */
final class DiscKeys
{
    /** In the order ps3netsrv tries them. */
    private const EXTENSIONS = ['dkey', 'key'];

    public function __construct(
        private readonly LibraryPath $paths,
        private readonly ConversionQueue $queue,
    ) {}

    /**
     * The console's decrypter that reads this file's format, or null: what
     * decides whether a file shows a key and a state at all.
     */
    public function decrypterFor(Console $console, GameFile $file): ?Converter
    {
        return Converters::onPage($console, Converter::PAGE_DECRYPT)
            ->first(function (Converter $converter) use ($file): bool {
                return in_array(Str::lower((string) $file->extension), $converter->from(), true);
            });
    }

    /** Whether a key decrypts this file's disc: checked before a key is kept, and when one is found beside it. */
    public function fits(GameFile $file, string $key): bool
    {
        return Ps3Disc::open(LibraryFolders::pathOf($file))?->keyMatches($key) ?? false;
    }

    /**
     * Queue files for decryption, keys put on record first since decrypting
     * deletes the .dkey. One nothing decrypts is counted as skipped, not thrown.
     *
     * @param  iterable<GameFile>  $files  with their game loaded
     * @return array{queued: list<Conversion>, skipped: int}
     */
    public function queueDecryption(iterable $files): array
    {
        $sets = [];
        $skipped = 0;

        foreach ($files as $file) {
            $console = $file->game->console();
            $converter = $console !== null ? $this->decrypterFor($console, $file) : null;
            $set = $converter !== null ? SourceSet::fromFile($file) : null;

            if ($converter === null || $set === null) {
                $skipped++;

                continue;
            }

            $this->record($file);
            $sets[$converter->key()][] = $set;
        }

        $queued = [];

        foreach ($sets as $converterKey => $forConverter) {
            $result = $this->queue->addMany($forConverter, $converterKey, []);
            array_push($queued, ...Arr::get($result, 'queued'));
            $skipped += (int) Arr::get($result, 'skipped');
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * A key as 32 upper-case hex digits, from a key file's contents — the hex
     * text a .dkey holds, or the 16 raw bytes some tools write — or null.
     */
    public static function normalise(string $contents): ?string
    {
        // Hex text is 32 characters, so 16 bytes can only be the key raw.
        if (strlen($contents) === 16) {
            return Str::upper(bin2hex($contents));
        }

        $hex = Str::of($contents)->replaceMatches('/\s+/', '')->value();

        return Str::isMatch('/^[0-9a-f]{32}$/i', $hex) ? Str::upper($hex) : null;
    }

    /**
     * The key files beside an image, absolute, the one ps3netsrv reads first first.
     *
     * @param  string  $image  absolute
     * @return list<string>
     */
    public function keyFiles(string $image): array
    {
        $stem = Str::beforeLast($image, '.');

        return array_values(collect(self::EXTENSIONS)
            ->map(function (string $extension) use ($stem): string {
                return $stem.'.'.$extension;
            })
            ->filter(function (string $path): bool {
                return is_file($path) && ! is_link($path);
            })
            ->all());
    }

    /**
     * The key for an image, from the first key file beside it that holds one.
     *
     * @param  string  $image  absolute
     */
    public function read(string $image): ?string
    {
        foreach ($this->keyFiles($image) as $file) {
            $key = self::normalise((string) file_get_contents($file, length: 256));

            if ($key !== null) {
                return $key;
            }
        }

        return null;
    }

    /** The key in the file beside a library image, if any — not the one on record. */
    public function onDisk(GameFile $file): ?string
    {
        return $this->read(LibraryFolders::pathOf($file));
    }

    /**
     * The key to show for a library file: the one on record, which outlives
     * decrypting, else the one beside it.
     */
    public function known(GameFile $file): ?string
    {
        return $file->meta->disc_key ?? $this->onDisk($file);
    }

    /**
     * Put the key beside a file on record, so it is still known once
     * decrypting has deleted it. Returns the key, or null when there is none.
     */
    public function record(GameFile $file): ?string
    {
        $key = $this->onDisk($file);

        if ($key !== null && $key !== $file->meta?->disc_key) {
            $file->rememberMeta(['disc_key' => $key]);
        }

        return $key;
    }

    /**
     * Where a library file stands: queued, what the toolbox read of it, and whether its
     * key is here. $decrypting from decrypting(), asked once for a whole list.
     */
    public function state(GameFile $file, bool $decrypting = false): DecryptState
    {
        $encrypted = $file->meta?->encrypted;

        return DecryptState::for($encrypted, $encrypted === true && $this->onDisk($file) !== null, $decrypting);
    }

    /**
     * Which of these files have a decryption waiting or running, in one query.
     *
     * @param  iterable<int>  $fileIds
     * @return list<int>
     */
    public function decrypting(iterable $fileIds): array
    {
        return array_values(collect($this->queue->waiting($fileIds))
            ->filter(function (array $converters): bool {
                return collect($converters)->contains(function (string $key): bool {
                    return Converters::make($key)?->page() === Converter::PAGE_DECRYPT;
                });
            })
            ->keys()
            ->map(function (int|string $id): int {
                return (int) $id;
            })
            ->all());
    }

    /**
     * Keep a key beside a library file as `<name>.dkey`, replacing one there.
     * The key is checked by the caller, against the disc; here only its shape.
     *
     * @throws LibraryPathException
     * @throws InvalidArgumentException
     */
    public function save(Console $console, GameFile $file, string $key): void
    {
        $relative = $this->paths->consoleRelative($console, $file->path);
        $normalised = self::normalise($key);

        if ($normalised === null) {
            throw new InvalidArgumentException('A disc key is 32 hex digits.');
        }

        if ($relative === null) {
            throw LibraryPathException::outsideRoot($file->path);
        }

        $this->paths->put($console, Str::beforeLast($relative, '.').'.dkey', $normalised."\n");
        $file->rememberMeta(['disc_key' => $normalised]);
    }
}
