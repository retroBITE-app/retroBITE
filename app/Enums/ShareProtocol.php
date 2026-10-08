<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Console;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The protocols retroBITE exposes the library over. Owns their port numbers so
 * the dashboard panel stops hardcoding them in markup.
 */
enum ShareProtocol: string
{
    case Smb = 'smb';
    case Ftp = 'ftp';
    case Ps3netsrv = 'ps3netsrv';

    /**
     * The protocols the share container runs, so a switched-off one is neither probed nor drawn.
     *
     * @return array<int, self>
     */
    public static function enabled(): array
    {
        return collect(self::cases())
            ->filter(function (self $protocol): bool {
                return $protocol !== self::Ps3netsrv || (bool) config('settings.network.ps3netsrv');
            })
            ->values()
            ->all();
    }

    /**
     * Human-readable name for the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Smb => 'SMB',
            self::Ftp => 'FTP',
            self::Ps3netsrv => 'ps3netsrv',
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
            self::Ps3netsrv => 38008,
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
            self::Ps3netsrv => '38008',
        };
    }

    /**
     * Whether a client signs in with the share account; ps3netsrv has no login.
     */
    public function authenticated(): bool
    {
        return $this !== self::Ps3netsrv;
    }

    /**
     * The installed consoles this protocol serves: ps3netsrv's root is the ps3 folder alone.
     *
     * @param  Collection<int, Console>  $consoles
     * @return Collection<int, Console>
     */
    public function serves(Collection $consoles): Collection
    {
        if ($this !== self::Ps3netsrv) {
            return $consoles;
        }

        $folders = (array) config('settings.network.ps3netsrv_folders', []);

        return $consoles
            ->filter(function (Console $console) use ($folders): bool {
                return in_array($console->folder, $folders, true);
            })
            ->values();
    }

    /**
     * A connection string for one share folder. ps3netsrv serves one root, so
     * webMAN is given the address and port, and the list the folder is in.
     */
    public function connectionString(string $hostIp, string $folder): string
    {
        return match ($this) {
            self::Smb => '\\\\'.$hostIp.'\\'.$folder,
            self::Ftp => 'ftp://'.$hostIp.'/'.$folder,
            self::Ps3netsrv => $hostIp.':'.$this->port().self::webmanList($folder),
        };
    }

    /** " · PS2ISO" for a library folder ps3netsrv serves; '' for one it does not. */
    private static function webmanList(string $folder): string
    {
        $name = array_search($folder, (array) config('settings.network.ps3netsrv_folders', []), true);

        return is_string($name) ? ' · '.$name : '';
    }

    /**
     * webMAN's folder names and the library folders behind them, from PS3NETSRV_FOLDERS
     * ("PS3ISO=ps3 PS2ISO=ps2"), refusing what the share container refuses.
     *
     * @return array<string, string> webMAN name => library folder
     */
    public static function ps3netsrvFolders(string $spec): array
    {
        return Str::of(trim($spec))
            ->split('/\s+/')
            ->filter(function (string $pair): bool {
                return Str::isMatch('/^(PS3ISO|PS2ISO|PSXISO|PSPISO|BDISO|DVDISO|GAMES|PKG)=[^.\/][^\/]*$/', $pair);
            })
            ->mapWithKeys(function (string $pair): array {
                return [Str::before($pair, '=') => Str::after($pair, '=')];
            })
            ->all();
    }
}
