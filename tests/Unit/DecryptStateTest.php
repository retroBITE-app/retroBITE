<?php

use App\Enums\DecryptState;

it('puts a decryption under way ahead of everything the disc says', function () {
    expect(DecryptState::for(true, true, decrypting: true))->toBe(DecryptState::Decrypting)
        ->and(DecryptState::for(null, null, decrypting: true))->toBe(DecryptState::Decrypting)
        ->and(DecryptState::for(true, true))->toBe(DecryptState::Ready)
        ->and(DecryptState::for(true, null))->toBe(DecryptState::NeedsKey)
        ->and(DecryptState::for(true, false))->toBe(DecryptState::WrongKey)
        ->and(DecryptState::for(false, null))->toBe(DecryptState::Decrypted)
        ->and(DecryptState::for(false, false))->toBe(DecryptState::Decrypted)
        ->and(DecryptState::for(null, true))->toBe(DecryptState::Unchecked);
});

it('tells a disc never read from one read without an answer', function () {
    expect(DecryptState::for(null, null, inspected: false))->toBe(DecryptState::Unchecked)
        ->and(DecryptState::for(null, null, inspected: true))->toBe(DecryptState::Unknown)
        ->and(DecryptState::Unknown->dashed())->toBeTrue()
        ->and(DecryptState::Unchecked->dashed())->toBeTrue();
});

it('wants a key from the user only where there is none or it does not fit', function () {
    expect(collect(DecryptState::cases())->filter(function (DecryptState $state): bool {
        return $state->wantsKey();
    })->values()->all())->toBe([DecryptState::NeedsKey, DecryptState::WrongKey])
        ->and(DecryptState::WrongKey->tone())->toBe('danger');
});
