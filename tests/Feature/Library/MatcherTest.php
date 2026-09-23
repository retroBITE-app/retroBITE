<?php

use App\Enums\FileRole;
use App\Enums\GameStatus;
use App\Exceptions\ScreenScraper\QuotaExhausted;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\Media;
use App\Services\GameMatcher;
use App\Support\Matching\MatchOutcome;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

function providerHit(array $jeu = []): void
{
    Http::fake(['*' => Http::response([
        'response' => [
            'ssuser' => ['maxthreads' => '1', 'requeststoday' => '1', 'maxrequestsperday' => '20000'],
            'jeu' => array_replace([
                'id' => '19256',
                'noms' => [['region' => 'ss', 'text' => 'Final Fantasy IX']],
                'editeur' => ['text' => 'Square'],
                'developpeur' => ['text' => 'Square'],
                'joueurs' => ['text' => '1'],
                'dates' => [['region' => 'ss', 'text' => '2000-02-07']],
                'regions' => ['regions_shortname' => ['eu']],
                'medias' => [],
            ], $jeu),
        ],
    ], 200)]);
}

/**
 * Several answers in order.
 *
 * Http::fake() merges stubs rather than replacing them, so calling it twice
 * with the same pattern leaves the first answer winning both times.
 */
function providerSequence(array ...$jeux): void
{
    $sequence = Http::sequence();

    foreach ($jeux as $jeu) {
        $sequence->push([
            'response' => [
                'ssuser' => ['maxthreads' => '1', 'requeststoday' => '1', 'maxrequestsperday' => '20000'],
                'jeu' => array_replace([
                    'id' => '19256',
                    'noms' => [['region' => 'ss', 'text' => 'Final Fantasy IX']],
                    'medias' => [],
                ], $jeu),
            ],
        ]);
    }

    Http::fake(['*' => $sequence]);
}

function providerMiss(): void
{
    Http::fake(['*' => Http::response(
        mb_convert_encoding('Erreur : Jeu non trouvée !', 'ISO-8859-1', 'UTF-8'), 404
    )]);
}

function matcher(): GameMatcher
{
    return app(GameMatcher::class);
}

function psxGame(array $file = []): Game
{
    $game = Game::factory()->forConsole('psx')->create(['title' => 'FF9 (Disc 1)', 'slug' => 'ff9-disc-1']);

    GameFile::factory()->for($game)->create(array_replace([
        'path' => 'psx/FF9 (Disc 1).bin',
        'filename' => 'FF9 (Disc 1).bin',
        'extension' => 'bin',
        'size_bytes' => 431495856,
        'role' => FileRole::Track,
        'disc_number' => 1,
    ], $file));

    return $game;
}

it('identifies a game from its filename and size alone', function () {
    providerHit();
    $game = psxGame();

    $result = matcher()->match($game);

    expect($result->outcome)->toBe(MatchOutcome::Matched);

    $game->refresh();

    expect($game->screenscraper_id)->toBe(19256)
        ->and($game->title)->toBe('Final Fantasy IX')
        ->and($game->slug)->toBe('final-fantasy-ix')
        ->and($game->status)->toBe(GameStatus::Matched)
        ->and($game->publisher)->toBe('Square')
        ->and($game->matched_at)->not->toBeNull();

    // No checksum was computed: reading 430 MB to learn what the filename
    // already told the provider would be wasted work.
    expect($game->files()->sole()->md5)->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'romnom=FF9')
        && str_contains($request->url(), 'romtaille=431495856')
        && ! str_contains($request->url(), 'md5='));
});

it('describes a disc image as an iso and a cartridge as a rom', function (string $extension, string $expected) {
    providerHit();
    $game = psxGame(['extension' => $extension, 'filename' => "game.{$extension}"]);

    matcher()->match($game);

    Http::assertSent(fn ($request) => str_contains($request->url(), "romtype={$expected}"));
})->with([
    'disc image' => ['iso', 'iso'],
    'cue track' => ['bin', 'iso'],
    'cartridge' => ['sfc', 'rom'],
]);

it('asks for checksums rather than giving up when name and size miss', function () {
    providerMiss();
    $game = psxGame();

    $result = matcher()->match($game);

    expect($result->outcome)->toBe(MatchOutcome::NeedsChecksums)
        ->and($result->file->filename)->toBe('FF9 (Disc 1).bin')
        // Not recorded as unmatched: the question has not been fully asked.
        ->and($game->refresh()->status)->toBe(GameStatus::Placeholder);
});

it('retries with checksums immediately when the file already has them', function () {
    providerMiss();
    $game = psxGame(['md5' => 'ca32e3bfb4507f9713f37b5d345baab1', 'crc' => 'BE55BF2A', 'sha1' => str_repeat('a', 40), 'hashed_at' => now()]);

    $result = matcher()->match($game);

    expect($result->outcome)->toBe(MatchOutcome::Unmatched)
        ->and($game->refresh()->status)->toBe(GameStatus::Unmatched);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'md5=ca32e3bfb4507f9713f37b5d345baab1'));
});

it('records what was asked when a game cannot be identified', function () {
    providerMiss();
    $game = psxGame(['md5' => str_repeat('b', 32), 'hashed_at' => now()]);

    matcher()->match($game);

    $activity = Activity::where('log_name', 'screenscraper')->latest('id')->first();

    // The row somebody opens to ask why a game was never identified: what was
    // sent is the whole answer.
    expect($activity->properties['outcome'])->toBe('miss')
        ->and($activity->properties['criteria']['romnom'])->toBe('FF9 (Disc 1).bin')
        ->and($activity->properties['criteria']['md5'])->toBe(str_repeat('b', 32))
        ->and($activity->subject->is($game))->toBeTrue()
        // Credentials are added by the client and never enter the log.
        ->and(json_encode($activity->properties))->not->toContain('sspassword');
});

it('folds a second dump of the same game into the one already identified', function () {
    providerHit();
    $first = psxGame();
    matcher()->match($first);

    // A different region of the same title: the provider answers with the same
    // id, which is the identity, so these are one game.
    $second = Game::factory()->forConsole('psx')->create(['title' => 'FF9 (USA)', 'slug' => 'ff9-usa']);
    GameFile::factory()->for($second)->create(['path' => 'psx/FF9 (USA).bin', 'filename' => 'FF9 (USA).bin', 'extension' => 'bin', 'role' => FileRole::Track]);

    $result = matcher()->match($second);

    expect($result->outcome)->toBe(MatchOutcome::Merged)
        ->and($result->game->is($first))->toBeTrue()
        ->and(Game::count())->toBe(1)
        ->and($first->refresh()->files()->count())->toBe(2)
        // The placeholder is gone, but neither file was lost with it.
        ->and(GameFile::count())->toBe(2);
});

it('reads disc numbers off the provider rom list by checksum', function () {
    providerHit(['roms' => [
        ['romfilename' => 'a.bin', 'romnumsupport' => '1', 'romtotalsupport' => '4', 'rommd5' => 'AAAA1111AAAA1111AAAA1111AAAA1111'],
        ['romfilename' => 'b.bin', 'romnumsupport' => '3', 'romtotalsupport' => '13', 'rommd5' => 'BBBB2222BBBB2222BBBB2222BBBB2222'],
    ]]);

    $game = psxGame(['md5' => 'aaaa1111aaaa1111aaaa1111aaaa1111', 'hashed_at' => now(), 'disc_number' => null]);
    GameFile::factory()->for($game)->create([
        'path' => 'psx/b.bin', 'filename' => 'b.bin', 'extension' => 'bin',
        'role' => FileRole::Track, 'md5' => 'bbbb2222bbbb2222bbbb2222bbbb2222', 'hashed_at' => now(),
    ]);

    matcher()->match($game);

    // Filenames differ between dump sets, so the checksum is what matches.
    // romtotalsupport is ignored: it is contributed per entry and the same
    // game carries /4 and /13 here.
    expect($game->files()->where('filename', 'FF9 (Disc 1).bin')->sole()->disc_number)->toBe(1)
        ->and($game->files()->where('filename', 'b.bin')->sole()->disc_number)->toBe(3);
});

it('spends one lookup on a whole multi-disc set', function () {
    providerHit();
    $game = psxGame();

    foreach (range(2, 4) as $disc) {
        GameFile::factory()->for($game)->create([
            'path' => "psx/FF9 (Disc {$disc}).bin", 'filename' => "FF9 (Disc {$disc}).bin",
            'extension' => 'bin', 'role' => FileRole::Track, 'disc_number' => $disc,
        ]);
    }

    matcher()->match($game);

    // Four discs, one request. The other three would return the same id at a
    // tenfold penalty whenever one missed.
    Http::assertSentCount(1);
});

it('never marks a game unmatched because the quota ran out', function () {
    // The failure this whole design exists to prevent: a scan that runs past
    // the daily allowance would otherwise condemn every remaining game.
    Http::fake(['*' => Http::response(
        mb_convert_encoding("Votre quota de scrape est dépassé pour aujourd'hui !", 'ISO-8859-1', 'UTF-8'), 430
    )]);

    $game = psxGame();

    expect(fn () => matcher()->match($game))->toThrow(QuotaExhausted::class);

    expect($game->refresh()->status)->toBe(GameStatus::Placeholder)
        ->and($game->screenscraper_id)->toBeNull();
});

it('skips a game whose console the provider does not know', function () {
    $game = Game::factory()->forConsole('snes')->create();
    GameFile::factory()->for($game)->create(['path' => 'snes/a.sfc']);
    config()->set('consoles.snes.screenscraper_id', null);

    $result = matcher()->match($game);

    expect($result->outcome)->toBe(MatchOutcome::Skipped)
        ->and($result->reason)->toContain('not mapped');

    Http::assertNothingSent();
});

it('skips a game with nothing the provider could identify', function () {
    $game = Game::factory()->forConsole('psx')->create();
    GameFile::factory()->for($game)->create(['path' => 'psx/a.m3u', 'role' => FileRole::Playlist]);

    $result = matcher()->match($game);

    expect($result->outcome)->toBe(MatchOutcome::Skipped);

    Http::assertNothingSent();
});

it('keeps two games apart when the provider gives them the same title', function () {
    providerSequence([], ['id' => '99999']);

    matcher()->match(psxGame());

    $other = Game::factory()->forConsole('psx')->create(['title' => 'Other', 'slug' => 'other']);
    GameFile::factory()->for($other)->create(['path' => 'psx/other.bin', 'filename' => 'other.bin', 'extension' => 'bin', 'role' => FileRole::Track]);

    matcher()->match($other);

    // Same title, different provider id: two games, and the unique index on
    // (console, slug) must not reject the second.
    expect(Game::count())->toBe(2)
        ->and(Game::pluck('slug')->sort()->values()->all())->toBe(['final-fantasy-ix', 'final-fantasy-ix-2']);
});

it('records the provider rating on a game it identifies', function () {
    providerHit(['note' => ['text' => '18']]);

    $game = psxGame();
    matcher()->match($game);

    // Their mark out of twenty, ours out of a hundred.
    expect($game->refresh()->rating)->toBe(90);
});

it('leaves the rating null when the provider offers none', function () {
    providerHit();

    $game = psxGame();
    matcher()->match($game);

    expect($game->refresh()->rating)->toBeNull();
});

it('hands a rating to the surviving game of a merge that had none', function () {
    // The first dump is identified before ratings exist, which is every game
    // in a library that predates the column.
    providerSequence(
        ['id' => '19256'],
        ['id' => '19256', 'note' => ['text' => '18']],
    );

    $first = psxGame();
    matcher()->match($first);
    expect($first->refresh()->rating)->toBeNull();

    $second = Game::factory()->forConsole('psx')->create(['title' => 'FF9 (USA)', 'slug' => 'ff9-usa']);
    GameFile::factory()->for($second)->create(['path' => 'psx/FF9 (USA).bin', 'filename' => 'FF9 (USA).bin', 'extension' => 'bin', 'role' => FileRole::Track]);

    // The answer about the second dump is about the same provider id, so it is
    // about the survivor too — and it has already been paid for.
    expect(matcher()->match($second)->outcome)->toBe(MatchOutcome::Merged)
        ->and($first->refresh()->rating)->toBe(90);
});

it('leaves a rating the surviving game already holds alone', function () {
    providerSequence(
        ['id' => '19256', 'note' => ['text' => '18']],
        ['id' => '19256', 'note' => ['text' => '12']],
    );

    $first = psxGame();
    matcher()->match($first);

    $second = Game::factory()->forConsole('psx')->create(['title' => 'FF9 (USA)', 'slug' => 'ff9-usa']);
    GameFile::factory()->for($second)->create(['path' => 'psx/FF9 (USA).bin', 'filename' => 'FF9 (USA).bin', 'extension' => 'bin', 'role' => FileRole::Track]);

    matcher()->match($second);

    expect($first->refresh()->rating)->toBe(90);
});

it('re-identifies a matched game by hand, dropping the artwork of the game it was', function () {
    Storage::fake('media');
    providerHit(['id' => '19257', 'noms' => [['region' => 'ss', 'text' => 'Final Fantasy VIII']]]);

    $game = psxGame();
    $game->update(['screenscraper_id' => 19256, 'status' => GameStatus::Matched]);
    Media::factory()->for($game)->create();

    $result = matcher()->assign($game, 19257);

    expect($result->outcome)->toBe(MatchOutcome::Matched)
        ->and($game->refresh()->screenscraper_id)->toBe(19257)
        ->and($game->title)->toBe('Final Fantasy VIII')
        ->and($game->media()->count())->toBe(0);
});

it('skips a hand-picked id the provider does not hold', function () {
    providerMiss();

    $game = psxGame();

    expect(matcher()->assign($game, 19256)->outcome)->toBe(MatchOutcome::Skipped)
        ->and($game->refresh()->screenscraper_id)->toBeNull();
});
