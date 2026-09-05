#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * ScreenScraper lookup for the PS2 test scripts, printed as `key<TAB>value`
 * lines. It exists so the shell scripts carry no provider credentials, endpoint
 * or payload shapes of their own — everything here delegates to the app's own
 * ScreenScraperService.
 *
 * Usage: php tests/lib/ps2-metadata.php <console-key> <name>
 *        php tests/lib/ps2-metadata.php --md5=<hash> <console-key>
 *        php tests/lib/ps2-metadata.php --id=<providerId> <console-key>
 *
 * Exit 1 is a miss, not an error: the caller writes what it can and moves on.
 */

use App\Services\ScreenScraperService;
use App\Support\Console as ConsoleMeta;
use Illuminate\Container\Container;
use Illuminate\Support\Arr;

$web = dirname(__DIR__, 2) . '/web';

require $web . '/vendor/autoload.php';

/** @var Container $container */
$container = require $web . '/bootstrap/container.php';

/**
 * Values must survive a tab-separated line, and a synopsis arrives with hard
 * newlines and HTML entities in it. Both are undone here so no consumer has to.
 */
function oneLine(?string $value): string
{
    $decoded = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim((string) preg_replace('/\s+/u', ' ', $decoded));
}

/**
 * ScreenScraper scores out of 20; the OPL theme picks a rating_0..rating_5
 * image. An absent score stays empty rather than becoming a real zero.
 */
function ratingOutOfFive(mixed $note): string
{
    $score = (float) oneLine(is_scalar($note) ? (string) $note : '');

    return $score > 0 ? (string) (int) round($score / 4) : '';
}

$args       = array_slice($argv, 1);
$providerId = 0;
$md5        = '';

foreach ($args as $index => $arg) {
    if (str_starts_with($arg, '--id=')) {
        $providerId = (int) substr($arg, 5);
        unset($args[$index]);
    }

    if (str_starts_with($arg, '--md5=')) {
        $md5 = substr($arg, 6);
        unset($args[$index]);
    }
}

$args        = array_values($args);
$consoleKey  = $args[0] ?? '';
$name        = $args[1] ?? '';
$console     = ConsoleMeta::tryFrom($consoleKey);

if ($console === null) {
    fwrite(STDERR, "Unknown console: {$consoleKey}" . PHP_EOL);

    exit(2);
}

$service = $container->make(ScreenScraperService::class);

// An md5 is an exact hit; a name search is a guess, and ScreenScraper happily
// answers "Ratchet & Clank" with Ratchet And Clank 3. Hash first where we have one.
if ($providerId === 0 && $md5 !== '') {
    $record = $service->lookupByMd5($console, $md5);

    if ($record !== null) {
        $providerId = (int) ($record['provider_id'] ?? 0);
    }
}

if ($providerId === 0) {
    $candidates = $service->search($console, $name);
    $providerId = (int) Arr::get($candidates, '0.provider_id', 0);
}

$record = $providerId > 0 ? $service->fetchById($providerId) : null;

if ($record === null) {
    exit(1);
}

$fields = [
    'provider_id'  => $record['provider_id'] ?? '',
    'title'        => $record['title'] ?? '',
    'description'  => $record['description'] ?? '',
    'genre'        => $record['genre'] ?? '',
    'release_date' => $record['release_date'] ?? '',
    'developer'    => $record['developer'] ?? '',
    'publisher'    => $record['publisher'] ?? '',
    'players'      => $record['players'] ?? '',
    'rating'       => ratingOutOfFive(Arr::get($record, 'raw.note.text')),
];

foreach ($fields as $key => $value) {
    fwrite(STDOUT, $key . "\t" . oneLine((string) $value) . PHP_EOL);
}

exit(0);
