<?php

use App\Enums\ShareProtocol;
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
