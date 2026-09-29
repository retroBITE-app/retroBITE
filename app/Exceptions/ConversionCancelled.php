<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Somebody pressed Cancel while a conversion was running. Not a failure. */
final class ConversionCancelled extends RuntimeException {}
