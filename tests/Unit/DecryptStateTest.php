<?php

use App\Enums\DecryptState;

it('puts a decryption under way ahead of everything the disc says', function () {
    expect(DecryptState::for(true, true, decrypting: true))->toBe(DecryptState::Decrypting)
        ->and(DecryptState::for(null, false, decrypting: true))->toBe(DecryptState::Decrypting)
        ->and(DecryptState::for(true, true))->toBe(DecryptState::Ready)
        ->and(DecryptState::for(true, false))->toBe(DecryptState::NeedsKey)
        ->and(DecryptState::for(false, false))->toBe(DecryptState::Decrypted)
        ->and(DecryptState::for(null, true))->toBe(DecryptState::Unchecked);
});
