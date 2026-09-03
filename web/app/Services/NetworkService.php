<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ShareProtocol;

/**
 * Probes whether the file-sharing services are accepting connections.
 */
class NetworkService
{
    private const TIMEOUT_SECONDS = 1.5;

    /**
     * Reachability of each share protocol, keyed by its value.
     *
     * @return array<string, bool>
     */
    public function status(): array
    {
        $host   = (string) config('settings.network.host_ip');
        $result = [];

        foreach (ShareProtocol::cases() as $protocol) {
            $result[$protocol->value] = $this->isPortOpen($host, $protocol->port());
        }

        return $result;
    }

    /**
     * Can we open a TCP connection to this port?
     *
     * A closed port is the normal answer before the file server is up, so it is
     * logged at debug — reporting it as an error filled the log on every poll.
     */
    public function isPortOpen(string $host, int $port): bool
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, self::TIMEOUT_SECONDS);

        if (is_resource($connection)) {
            fclose($connection);

            return true;
        }

        logger()->debug('Port closed', [
            'host'  => $host,
            'port'  => $port,
            'errno' => $errno,
            'error' => $errstr,
        ]);

        return false;
    }
}
