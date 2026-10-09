<?php

use App\Enums\ShareProtocol;
use App\Models\ConsoleSourceFolder;
use App\Support\Console;

/**
 * Which protocols the share container runs, and what ps3netsrv hands a PS3.
 */
it('offers ps3netsrv while the share container runs it', function () {
    config(['settings.network.ps3netsrv' => true]);

    expect(ShareProtocol::enabled())->toBe([ShareProtocol::Smb, ShareProtocol::Ftp, ShareProtocol::Ps3netsrv]);
});

it('drops ps3netsrv when it is switched off', function () {
    config(['settings.network.ps3netsrv' => false]);

    expect(ShareProtocol::enabled())->toBe([ShareProtocol::Smb, ShareProtocol::Ftp]);
});

it('gives webMAN an address and port, and the list a folder is in, rather than a path', function () {
    expect(ShareProtocol::Ps3netsrv->port())->toBe(38008)
        ->and(ShareProtocol::Ps3netsrv->connectionString('192.168.1.10', 'ps3'))->toBe('192.168.1.10:38008 · PS3ISO')
        ->and(ShareProtocol::Ps3netsrv->connectionString('192.168.1.10', 'ps2'))->toBe('192.168.1.10:38008 · PS2ISO')
        ->and(ShareProtocol::Ps3netsrv->connectionString('192.168.1.10', 'gc'))->toBe('192.168.1.10:38008')
        ->and(ShareProtocol::Ps3netsrv->authenticated())->toBeFalse()
        ->and(ShareProtocol::Smb->authenticated())->toBeTrue();
});

it('serves PS3, PS2 and PS1 over ps3netsrv by default, and every console over the others', function () {
    $consoles = collect([new Console('gc'), new Console('ps2'), new Console('ps3'), new Console('psx')]);

    expect(ShareProtocol::Ps3netsrv->serves($consoles)->pluck('key')->all())->toBe(['ps2', 'ps3', 'psx'])
        ->and(ShareProtocol::Smb->serves($consoles)->pluck('key')->all())->toBe(['gc', 'ps2', 'ps3', 'psx']);
});

it('follows the folder map the share container is given', function () {
    config(['settings.network.ps3netsrv_folders' => ShareProtocol::ps3netsrvFolders('PS3ISO=ps3 PSPISO=psp')]);

    expect(ShareProtocol::Ps3netsrv->serves(collect([new Console('ps2'), new Console('psp')]))->pluck('key')->all())->toBe(['psp']);
});

it('reads the folder map as the share container does, leaving out what it refuses', function () {
    expect(ShareProtocol::ps3netsrvFolders('PS3ISO=ps3 PS2ISO=ps2 PSXISO=psx'))->toBe(['PS3ISO' => 'ps3', 'PS2ISO' => 'ps2', 'PSXISO' => 'psx'])
        ->and(ShareProtocol::ps3netsrvFolders(" PS3ISO=ps3\tBAD=x PS2ISO=../etc PSXISO=.retrobite-uploads PSPISO PKG=a/b DVDISO=movies "))->toBe(['PS3ISO' => 'ps3', 'DVDISO' => 'movies'])
        ->and(ShareProtocol::ps3netsrvFolders(''))->toBe([]);
});

it('serves a console from the folder the library really keeps it in, and says when that is not the one ps3netsrv serves', function () {
    ConsoleSourceFolder::add(new Console('ps3'), 'ps3-isos');

    // PS3NETSRV_FOLDERS still says PS3ISO=ps3: webMAN would list an empty folder.
    expect(ShareProtocol::Ps3netsrv->serves(collect([new Console('ps3')]))->pluck('key')->all())->toBe(['ps3'])
        ->and(ShareProtocol::Ps3netsrv->misplaced(new Console('ps3')))->toBe('ps3-isos')
        ->and(ShareProtocol::Smb->misplaced(new Console('ps3')))->toBeNull();

    // Mapped to the real folder, it is served from there and nothing is amiss.
    config(['settings.network.ps3netsrv_folders' => ShareProtocol::ps3netsrvFolders('PS3ISO=ps3-isos')]);

    expect(ShareProtocol::Ps3netsrv->misplaced(new Console('ps3')))->toBeNull()
        ->and(ShareProtocol::Ps3netsrv->servedFolder(new Console('ps3')))->toBe('ps3-isos')
        ->and(ShareProtocol::Ps3netsrv->connectionString('192.168.1.10', 'ps3-isos'))->toBe('192.168.1.10:38008 · PS3ISO');
});

it('takes a whitelist only in the form ps3netsrv does', function (string $whitelist, bool $valid) {
    expect(ShareProtocol::ps3netsrvWhitelistValid($whitelist))->toBe($valid);
})->with([
    ['192.168.1.*', true],
    ['10.0.0.5', true],
    ['*.*.*.*', true],
    ['192.168.1.0/24', false],
    ['192.168.1', false],
    ['1.2.3.4; rm -rf /', false],
]);
