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

it('gives webMAN an address and port rather than a path', function () {
    expect(ShareProtocol::Ps3netsrv->port())->toBe(38008)
        ->and(ShareProtocol::Ps3netsrv->connectionString('192.168.1.10', 'ps3'))->toBe('192.168.1.10:38008')
        ->and(ShareProtocol::Ps3netsrv->authenticated())->toBeFalse()
        ->and(ShareProtocol::Smb->authenticated())->toBeTrue();
});

it('serves the PlayStation 3 alone over ps3netsrv, and every console over the others', function () {
    $consoles = collect([new Console('ps2'), new Console('ps3')]);

    expect(ShareProtocol::Ps3netsrv->serves($consoles)->pluck('key')->all())->toBe(['ps3'])
        ->and(ShareProtocol::Smb->serves($consoles)->pluck('key')->all())->toBe(['ps2', 'ps3']);
});
