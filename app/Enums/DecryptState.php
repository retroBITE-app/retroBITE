<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a PS3 disc image stands on Tools → Decrypt and its game's page.
 *
 * Worked out, never stored: what the toolbox read (game_file_meta.encrypted,
 * and license_id for whether it has read the disc at all) and whether the key
 * beside the image fits it.
 */
enum DecryptState: string
{
    /** Not read yet. */
    case Unchecked = 'unchecked';

    /** Read, but nothing on the disc tells whether it is encrypted. */
    case Unknown = 'unknown';

    /** Encrypted, and no key beside it: will not play. */
    case NeedsKey = 'needs_key';

    /** Encrypted, with a key beside it that does not decrypt it: will not play. */
    case WrongKey = 'wrong_key';

    /** Encrypted, with its key: plays over ps3netsrv as it is, and can be decrypted. */
    case Ready = 'ready';

    /** Decrypted: plays anywhere, no key needed. */
    case Decrypted = 'decrypted';

    /** A decryption is waiting or running: nothing more to do until it is over. */
    case Decrypting = 'decrypting';

    /**
     * From the queue, the toolbox's reading and the key beside the image: $keyFits
     * is null with no key there. A queued decryption wins; an unknown encryption is
     * Unknown once the disc has been read, Unchecked before.
     */
    public static function for(?bool $encrypted, ?bool $keyFits, bool $decrypting = false, bool $inspected = false): self
    {
        return match (true) {
            $decrypting => self::Decrypting,
            $encrypted === null => $inspected ? self::Unknown : self::Unchecked,
            $encrypted === false => self::Decrypted,
            $keyFits === true => self::Ready,
            $keyFits === false => self::WrongKey,
            default => self::NeedsKey,
        };
    }

    /** Whether the image wants a key from the user: none beside it, or one that does not fit. */
    public function wantsKey(): bool
    {
        return $this === self::NeedsKey || $this === self::WrongKey;
    }

    /** The badge's text. */
    public function label(): string
    {
        return match ($this) {
            self::Unchecked => __('Not checked'),
            self::Unknown => __('Unknown'),
            self::NeedsKey => __('Needs key'),
            self::WrongKey => __('Wrong key'),
            self::Ready => __('Key ready'),
            self::Decrypted => __('Decrypted'),
            self::Decrypting => __('Decrypting'),
        };
    }

    /** The badge's tone, as x-badge takes it: warn for the one that will not start, danger for a key that is wrong. */
    public function tone(): string
    {
        return match ($this) {
            self::NeedsKey => 'warn',
            self::WrongKey => 'danger',
            self::Ready, self::Decrypting => 'accent',
            self::Decrypted, self::Unchecked, self::Unknown => 'muted',
        };
    }

    /** A dashed outline for the ones nothing is known about. */
    public function dashed(): bool
    {
        return $this === self::Unchecked || $this === self::Unknown;
    }

    /**
     * What to tell somebody who just pressed Check, as a toast: never nothing, and
     * Unchecked after a check means the disc could not be read at all.
     *
     * @return array{variant: 'success'|'warning'|'danger', text: string}
     */
    public function afterCheck(): array
    {
        return match ($this) {
            self::Unchecked => ['variant' => 'danger', 'text' => __('Could not read this image as a PS3 disc.')],
            self::Ready, self::Decrypted, self::Decrypting => ['variant' => 'success', 'text' => $this->hint()],
            self::NeedsKey, self::WrongKey, self::Unknown => ['variant' => 'warning', 'text' => $this->hint()],
        };
    }

    /** One line on what the state means for playing the game. */
    public function hint(): string
    {
        return match ($this) {
            self::Unchecked => __('Not read yet. Check it to see whether it is encrypted.'),
            self::Unknown => __('Read, but nothing on the disc tells whether it is encrypted.'),
            self::NeedsKey => __('Encrypted. It will not start until it has its disc key.'),
            self::WrongKey => __('Encrypted, and the key beside it does not fit this disc. Replace it with the key for this exact version.'),
            self::Ready => __('Plays over ps3netsrv with its key. Decrypt it to play without one.'),
            self::Decrypted => __('Decrypted. Plays without a key.'),
            self::Decrypting => __('In the decrypt queue: follow it on Tools → Decrypt.'),
        };
    }
}
