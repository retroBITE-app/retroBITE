<?php

declare(strict_types=1);

namespace App\Transfers\Smb;

use Icewind\SMB\AnonymousAuth;
use Icewind\SMB\IAuth;

/**
 * A guest login, as a Batocera box's share takes by default — icewind's own,
 * without asking for encryption.
 *
 * icewind asks every connection for encryption, and a guest session cannot
 * have it: Samba answers NT_STATUS_INVALID_PARAMETER_MIX, libsmbclient prints
 * that to the worker's output and carries on unencrypted. A console sent at
 * once is a connection per game, so the output filled with it and buried
 * the lines that mean something. Asked for nothing, it connects the same
 * and says nothing. A named account keeps asking; it can be encrypted.
 */
final class GuestAuth implements IAuth
{
    private readonly AnonymousAuth $anonymous;

    public function __construct()
    {
        $this->anonymous = new AnonymousAuth;
    }

    public function getUsername(): ?string
    {
        return $this->anonymous->getUsername();
    }

    public function getWorkgroup(): string
    {
        return $this->anonymous->getWorkgroup();
    }

    public function getPassword(): ?string
    {
        return $this->anonymous->getPassword();
    }

    public function getExtraCommandLineArguments(): string
    {
        return $this->anonymous->getExtraCommandLineArguments();
    }

    public function setExtraSmbClientOptions($smbClientState): void
    {
        $this->anonymous->setExtraSmbClientOptions($smbClientState);

        // Run after icewind has asked for encryption and before it connects,
        // so this is the last word. Absent without the extension, where there
        // is no state to set it on.
        if (defined('SMBCLIENT_OPT_ENCRYPT_LEVEL')) {
            smbclient_option_set($smbClientState, SMBCLIENT_OPT_ENCRYPT_LEVEL, SMBCLIENT_ENCRYPTLEVEL_NONE);
        }
    }
}
