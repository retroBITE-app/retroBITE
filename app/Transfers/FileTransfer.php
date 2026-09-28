<?php

declare(strict_types=1);

namespace App\Transfers;

/** One file, from one location to another. */
final class FileTransfer
{
    public function __construct(
        public readonly Location $from,
        public readonly Location $to,
    ) {}
}
