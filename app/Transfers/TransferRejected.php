<?php

declare(strict_types=1);

namespace App\Transfers;

use RuntimeException;

/** A transfer the server will not go on with, with a reason fit to show. */
final class TransferRejected extends RuntimeException {}
