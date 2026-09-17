<?php

use App\Services\ScreenScraperService;
use App\Support\Console;
use Illuminate\Support\Facades\Http;

/**
 * Media URLs arrive pre-signed with our developer and account passwords, and
 * the same list mixes in artwork belonging to the publisher and the genre.
 * Both have to be dealt with before anything is persisted or logged.
 */
function ssMediaResponse(array $medias): void
{
    Http::fake(['*' => Http::response([
        'response' => [
            'jeu' => [
                'id' => '19256',
                'noms' => [['region' => 'ss', 'text' => 'Final Fantasy IX']],
                'medias' => $medias,
                'roms' => [
                    ['romfilename' => 'FF IX (Disc 1).bin', 'romnumsupport' => '1', 'romtotalsupport' => '4', 'romcrc' => 'AAAA1111'],
                    ['romfilename' => 'FF IX (Disc 2).bin', 'romnumsupport' => '2', 'romtotalsupport' => '4', 'romcrc' => 'BBBB2222'],
                ],
            ],
        ],
    ], 200)]);
}

function ssFetch(): array
{
    return app(ScreenScraperService::class)->lookup(new Console('psx'), ['romnom' => 'x.bin', 'romtaille' => 1]);
}

it('strips credentials from every media url', function () {
    ssMediaResponse([[
        'type' => 'box-2D', 'parent' => 'jeu', 'region' => 'us', 'format' => 'png',
        'md5' => 'abc', 'size' => '5617',
        'url' => 'https://neoclone.screenscraper.fr/api2/mediaJeu.php?devid=me&devpassword=hunter2&softname=retroBITE&ssid=tomas&sspassword=topsecret&systemeid=57&jeuid=19256&media=box-2D(us)',
    ]]);

    $media = ssFetch()['medias'][0];

    expect($media['url'])
        ->not->toContain('hunter2')
        ->not->toContain('topsecret')
        ->not->toContain('devpassword')
        ->not->toContain('sspassword')
        // What identifies the media must survive, or the URL is useless.
        ->toContain('jeuid=19256')
        ->toContain('media=box-2D%28us%29');
});

it('keeps credentials out of the raw payload that gets logged', function () {
    ssMediaResponse([[
        'type' => 'ss', 'parent' => 'jeu',
        'url' => 'https://x/api2/mediaJeu.php?devpassword=hunter2&media=ss',
    ]]);

    expect(json_encode(ssFetch()['raw']))->not->toContain('hunter2');
});

it('drops artwork belonging to something other than the game', function () {
    ssMediaResponse([
        ['type' => 'box-2D', 'parent' => 'jeu', 'url' => 'https://x/a.png'],
        ['type' => 'pictoliste', 'parent' => 'editeur', 'url' => 'https://x/publisher.png'],
        ['type' => 'pictomonochrome', 'parent' => 'genre', 'url' => 'https://x/genre.png'],
    ]);

    $medias = ssFetch()['medias'];

    expect($medias)->toHaveCount(1)
        ->and($medias[0]['type'])->toBe('box-2D');
});

it('tolerates media that carries no region', function () {
    // fanart and video have no region key at all.
    ssMediaResponse([['type' => 'fanart', 'parent' => 'jeu', 'url' => 'https://x/f.png']]);

    expect(ssFetch()['medias'][0]['region'])->toBeNull();
});

it('exposes the rom list so disc numbers can be recovered', function () {
    ssMediaResponse([]);

    $roms = ssFetch()['roms'];

    expect($roms)->toHaveCount(2)
        ->and($roms[1]['romnumsupport'])->toBe('2');
});
