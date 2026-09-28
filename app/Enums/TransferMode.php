<?php

declare(strict_types=1);

namespace App\Enums;

/** Whether a transfer leaves the source where it was. */
enum TransferMode: string
{
    case Copy = 'copy';
    case Move = 'move';
}
