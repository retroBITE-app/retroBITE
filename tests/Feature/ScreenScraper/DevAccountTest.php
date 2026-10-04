<?php

/**
 * The developer account retroBITE ships with gives way to one in .env — but
 * only as a pair, since an id with the shipped password would fail every call.
 */
function screenScraperConfigWith(array $env): array
{
    foreach ($env as $key => $value) {
        $_SERVER[$key] = $_ENV[$key] = $value;
    }

    try {
        return require config_path('screenscraper.php');
    } finally {
        foreach (array_keys($env) as $key) {
            unset($_SERVER[$key], $_ENV[$key]);
        }
    }
}

it('uses the shipped developer account when .env names none', function () {
    $config = screenScraperConfigWith([]);

    expect($config['dev_id'])->not->toBe('')
        ->and($config['dev_password'])->not->toBe('');
});

it('uses the developer account from .env when both halves are set', function () {
    $config = screenScraperConfigWith([
        'SCREENSCRAPER_DEV_ID' => 'myfork',
        'SCREENSCRAPER_DEV_PASSWORD' => 'hunter2',
    ]);

    expect($config['dev_id'])->toBe('myfork')
        ->and($config['dev_password'])->toBe('hunter2');
});

it('keeps the shipped pair when .env sets only one half', function () {
    $shipped = screenScraperConfigWith([]);

    $config = screenScraperConfigWith(['SCREENSCRAPER_DEV_ID' => 'myfork']);

    expect($config['dev_id'])->toBe($shipped['dev_id'])
        ->and($config['dev_password'])->toBe($shipped['dev_password']);
});
