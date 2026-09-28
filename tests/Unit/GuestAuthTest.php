<?php

use App\Transfers\Smb\GuestAuth;

/*
 * A guest session cannot be encrypted, and asking for it anyway had
 * libsmbclient print NT_STATUS_INVALID_PARAMETER_MIX for every game sent.
 */

it('logs in as a guest without asking for encryption', function () {
    $state = smbclient_state_new();

    // What icewind sets before handing the state to the login.
    smbclient_option_set($state, SMBCLIENT_OPT_ENCRYPT_LEVEL, SMBCLIENT_ENCRYPTLEVEL_REQUEST);
    (new GuestAuth)->setExtraSmbClientOptions($state);

    expect(smbclient_option_get($state, SMBCLIENT_OPT_ENCRYPT_LEVEL))->toBe(SMBCLIENT_ENCRYPTLEVEL_NONE)
        ->and(smbclient_option_get($state, SMBCLIENT_OPT_AUTO_ANONYMOUS_LOGIN))->toBeTrue()
        ->and((new GuestAuth)->getUsername())->toBeNull();

    smbclient_state_free($state);
})->skip(! extension_loaded('smbclient'), 'The smbclient extension is in the image, not on every host.');
