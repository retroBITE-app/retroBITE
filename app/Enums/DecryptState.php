<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a PS3 disc image stands on Tools → Decrypt and its game's page.
 *
 * Worked out from two facts, never stored: what the toolbox read
 * (game_files.encrypted) and whether a key is beside the image.
 */
enum DecryptState: string
{
    /** Not read yet, or not an image whose encryption can be told. */
    case Unchecked = 'unchecked';

    /** Encrypted, and no key beside it: will not play. */
    case NeedsKey = 'needs_key';

    /** Encrypted, with its key: plays over ps3netsrv as it is, and can be decrypted. */
    case Ready = 'ready';

    /** Decrypted: plays anywhere, no key needed. */
    case Decrypted = 'decrypted';

    /** From the toolbox's reading and the key beside the image. Unread falls back to Unchecked. */
    public static function for(?bool $encrypted, bool $hasKey): self
    {
        return match (true) {
            $encrypted === null => self::Unchecked,
            $encrypted === false => self::Decrypted,
            $hasKey => self::Ready,
            default => self::NeedsKey,
        };
    }

    /** The badge's text. */
    public function label(): string
    {
        return match ($this) {
            self::Unchecked => __('Not checked'),
            self::NeedsKey => __('Needs key'),
            self::Ready => __('Key ready'),
            self::Decrypted => __('Decrypted'),
        };
    }

    /** One line on what the state means for playing the game. */
    public function hint(): string
    {
        return match ($this) {
            self::Unchecked => __('Not read yet. Check it to see whether it is encrypted.'),
            self::NeedsKey => __('Encrypted. It will not start until it has its disc key.'),
            self::Ready => __('Plays over ps3netsrv with its key. Decrypt it to play without one.'),
            self::Decrypted => __('Decrypted. Plays without a key.'),
        };
    }
}
