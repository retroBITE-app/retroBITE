<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The protocols retroBITE exposes the library over. Owns their port numbers so
 * the dashboard panel stops hardcoding them in markup.
 */
enum ShareProtocol: string
{
    case Smb = 'smb';
    case Ftp = 'ftp';

    /**
     * Human-readable name for the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Smb => 'SMB',
            self::Ftp => 'FTP',
        };
    }

    /**
     * Port the status check probes.
     */
    public function port(): int
    {
        return match ($this) {
            self::Smb => 445,
            self::Ftp => 21,
        };
    }

    /**
     * Ports worth showing the user, as displayed on the dashboard.
     */
    public function displayPorts(): string
    {
        return match ($this) {
            self::Smb => '139, 445',
            self::Ftp => '21',
        };
    }

    /**
     * A connection string for one share folder.
     */
    public function connectionString(string $hostIp, string $folder): string
    {
        return match ($this) {
            self::Smb => '\\\\'.$hostIp.'\\'.$folder,
            self::Ftp => 'ftp://'.$hostIp.'/'.$folder,
        };
    }
}
