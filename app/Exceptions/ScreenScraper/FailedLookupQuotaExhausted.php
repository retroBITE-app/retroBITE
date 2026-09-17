<?php

declare(strict_types=1);

namespace App\Exceptions\ScreenScraper;

/**
 * HTTP 431 — the daily quota for *failed* lookups is spent.
 *
 * This one is scarce: 2 000 against 20 000 successful requests, so it is ten
 * times cheaper to identify a file than to fail to. It runs out long before
 * the ordinary quota does on a library full of oddly named dumps, and it is
 * the reason the scanner never looks up .m3u or .cue files individually.
 */
final class FailedLookupQuotaExhausted extends QuotaExhausted {}
