<?php

use App\Support\Scanning\DumpName;

it('reads round and square tags apart, trimmed, in the order written, the extension left out', function () {
    $name = 'Aerostar (USA, Europe) ( Rev 1 ) [!] [b1].zip';

    expect(DumpName::roundTags($name))->toBe(['USA, Europe', 'Rev 1'])
        ->and(DumpName::squareTags($name))->toBe(['!', 'b1'])
        ->and(DumpName::roundTags('Plain.iso'))->toBe([])
        ->and(DumpName::roundTags('Odd (Name).(USA).iso'))->toBe(['Name', 'USA']);
});
