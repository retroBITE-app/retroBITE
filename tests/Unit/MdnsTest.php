<?php

use App\Transfers\Discovery\Mdns;

/*
 * The one piece of mDNS we speak: reading an answer. The packet is what a
 * Batocera box sends back to a PTR question for _smb._tcp.local, compression
 * pointers and all.
 */

function label(string $name): string
{
    $out = '';

    foreach (explode('.', $name) as $part) {
        $out .= chr(strlen($part)).$part;
    }

    return $out."\0";
}

it('reads the instance, its host and the address out of one answer', function () {
    $question = label('_smb._tcp.local'); // at offset 12
    $instance = chr(8).'BATOCERA'.chr(0xC0).chr(12); // BATOCERA + pointer to _smb._tcp.local
    $host = chr(8).'batocera'.chr(0xC0).chr(12 + 10); // batocera + pointer to local

    $ptr = chr(0xC0).chr(12).pack('nnNn', 12, 1, 10, strlen($instance)).$instance;
    $instanceOffset = 12 + strlen($question) + 4 + 2 + 10;
    $srvData = pack('nnn', 0, 0, 445).$host;
    $srv = chr(0xC0).chr($instanceOffset).pack('nnNn', 33, 0x8001, 10, strlen($srvData)).$srvData;
    $hostOffset = $instanceOffset + strlen($instance) + 2 + 10 + 6;
    $a = chr(0xC0).chr($hostOffset).pack('nnNn', 1, 0x8001, 10, 4).inet_pton('192.168.1.20');

    $packet = pack('nnnnnn', 0, 0x8400, 1, 1, 0, 2).$question.pack('nn', 12, 1).$ptr.$srv.$a;

    $records = (new ReflectionMethod(Mdns::class, 'parse'))->invoke(new Mdns, $packet);

    expect($records)->toBe([
        [12, '_smb._tcp.local', 'batocera._smb._tcp.local'],
        [33, 'batocera._smb._tcp.local', 'batocera.local'],
        [1, 'batocera.local', '192.168.1.20'],
    ]);
});

it('ignores questions and anything too short to be a packet', function () {
    $parse = new ReflectionMethod(Mdns::class, 'parse');

    expect($parse->invoke(new Mdns, 'short'))->toBe([])
        ->and($parse->invoke(new Mdns, pack('nnnnnn', 0, 0, 1, 0, 0, 0).label('_smb._tcp.local').pack('nn', 12, 1)))->toBe([]);
});
