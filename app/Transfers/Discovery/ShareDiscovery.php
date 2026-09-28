<?php

declare(strict_types=1);

namespace App\Transfers\Discovery;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Finding the machines on the network that share files, so nobody has to
 * look up an address.
 *
 * Four questions, each answered by different machines in different setups:
 *
 * - mDNS for `_smb._tcp` — macOS, most NAS boxes, Linux with Avahi, Batocera;
 * - NetBIOS for known names — what makes `\\BATOCERA` work from Windows;
 * - plain DNS for known names — some routers register their DHCP clients, so
 *   `batocera` resolves with no multicast at all;
 * - the SMB port on every address of the LAN's /24, then each answer's
 *   NetBIOS name asked for directly.
 *
 * The first two need the container on the LAN (network_mode: host), and
 * behind Docker's bridge neither reaches it. The last one does: a connection
 * to one address crosses the bridge where a multicast cannot. But the
 * container does not know which network the LAN is, so it scans only one it
 * has been told of — HOST_IP, TRANSFER_DISCOVERY_SUBNETS, or the address the
 * page was opened on — and only when somebody asks it to. Whatever answers the
 * other three is only kept if something listens on the SMB port.
 *
 * Every question here waits on the network, so this runs in a job, never in
 * a request.
 */
class ShareDiscovery
{
    private const SMB_PORT = 445;

    /** Seconds a scan waits for the SMB port to answer. A LAN answers in milliseconds. */
    private const SCAN_TIMEOUT = 1.5;

    /**
     * Connections opened at once while scanning: the whole /24, so a network
     * of silent addresses costs one timeout rather than one per batch.
     */
    private const SCAN_BATCH = 254;

    public function __construct(private readonly Mdns $mdns) {}

    /**
     * @param  list<string>  $hints  the address the page was opened on (this machine), then the
     *                               browser's: to guess the LAN by
     * @return list<FoundHost>
     */
    public function find(array $hints = []): array
    {
        $found = [];

        foreach ($this->mdns->browse() as $name => $address) {
            $found[$address] ??= new FoundHost($name, $address, 'mdns');
        }

        foreach ((array) config('transfer.discovery.names') as $name) {
            $name = (string) $name;

            foreach (['dns' => fn () => $this->dns($name), 'netbios' => fn () => $this->netbios($name)] as $via => $lookup) {
                $address = $lookup();

                if ($address !== null) {
                    $found[$address] ??= new FoundHost($name, $address, $via);

                    break;
                }
            }
        }

        $found = array_filter($found, fn (FoundHost $host): bool => $this->listens($host->address));

        foreach ($this->subnets($hints) as $subnet) {
            foreach ($this->scan($subnet) as $address) {
                $found[$address] ??= new FoundHost($this->nameOf($address) ?? $address, $address, 'scan');
            }
        }

        // retroBite's own share, on the machine it runs on, is not somewhere
        // to send to: HOST_IP, and the address the page was opened on.
        unset($found[(string) config('transfer.discovery.host_ip')], $found[$hints[0] ?? '']);

        return array_values($found);
    }

    /**
     * The LAN networks to scan, as /24 prefixes like "192.168.1": the ones
     * configured, else the one HOST_IP is on, else one a hint is on — never
     * one of this container's own, which is Docker's.
     *
     * @param  list<string>  $hints
     * @return list<string>
     */
    public function subnets(array $hints = []): array
    {
        $configured = array_filter(array_map(self::prefix(...), (array) config('transfer.discovery.subnets')));

        if ($configured !== []) {
            return array_values(array_unique($configured));
        }

        $own = array_filter(array_map(self::prefix(...), $this->ownAddresses()));
        $candidates = array_filter(array_map(self::prefix(...), [(string) config('transfer.discovery.host_ip'), ...$hints]));

        foreach ($candidates as $prefix) {
            if (! in_array($prefix, $own, true)) {
                return [$prefix];
            }
        }

        return [];
    }

    /**
     * Every address on a /24 that accepts a connection on the SMB port.
     *
     * Non-blocking connects, a batch at a time, so a whole network takes a few
     * seconds rather than one timeout per silent address.
     *
     * @return list<string>
     */
    protected function scan(string $prefix): array
    {
        $open = [];

        foreach (array_chunk(range(1, 254), self::SCAN_BATCH) as $batch) {
            $pending = [];

            foreach ($batch as $last) {
                $address = $prefix.'.'.$last;
                $socket = @stream_socket_client('tcp://'.$address.':'.self::SMB_PORT, $code, $message, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);

                if ($socket !== false) {
                    $pending[$address] = $socket;
                }
            }

            $until = microtime(true) + self::SCAN_TIMEOUT;

            while ($pending !== [] && ($left = $until - microtime(true)) > 0) {
                $read = $except = null;
                $write = array_values($pending);

                if (@stream_select($read, $write, $except, 0, (int) ($left * 1_000_000)) < 1) {
                    break;
                }

                foreach ($write as $socket) {
                    $address = array_search($socket, $pending, true);

                    // Writable means finished: connected, or refused. Only a
                    // connected socket has a peer.
                    if (is_string($address) && @stream_socket_get_name($socket, true) !== false) {
                        $open[] = $address;
                    }

                    fclose($socket);
                    unset($pending[$address]);
                }
            }

            foreach ($pending as $socket) {
                fclose($socket);
            }
        }

        return $open;
    }

    /**
     * What a machine calls itself: its NetBIOS name, asked of it directly
     * (`nmblookup -A`, one packet, which crosses the bridge), else what the
     * router's reverse DNS says.
     */
    protected function nameOf(string $address): ?string
    {
        try {
            $result = Process::timeout(5)->run(['nmblookup', '-A', $address]);

            // "\tBATOCERA        <00> -         B <ACTIVE>" — the machine's
            // own name, not a <GROUP> such as its workgroup.
            if ($result->successful() && preg_match('/^\s+(\S+)\s+<00> -\s+[BHMP] /m', $result->output(), $match) === 1) {
                return $match[1];
            }
        } catch (Throwable) {
            // No nmblookup: fall through to DNS.
        }

        $name = @gethostbyaddr($address);

        return is_string($name) && $name !== $address ? $name : null;
    }

    /** @return list<string> */
    protected function ownAddresses(): array
    {
        $addresses = [];

        foreach (function_exists('net_get_interfaces') ? (net_get_interfaces() ?: []) : [] as $interface) {
            foreach ($interface['unicast'] ?? [] as $unicast) {
                if (($unicast['family'] ?? null) === AF_INET && is_string($unicast['address'] ?? null)) {
                    $addresses[] = $unicast['address'];
                }
            }
        }

        return $addresses;
    }

    /**
     * The /24 an address or a CIDR is on, as "192.168.1"; null for anything
     * that is not a private IPv4 address, so nothing public is ever scanned.
     */
    private static function prefix(mixed $value): ?string
    {
        $address = trim((string) strtok((string) $value, '/'));

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) !== false
            || str_starts_with($address, '127.')) {
            return null;
        }

        return implode('.', array_slice(explode('.', $address), 0, 3));
    }

    /**
     * An address for what somebody typed: an address as it is, else a name by
     * DNS, mDNS for `.local`, and NetBIOS.
     */
    public function resolve(string $name): ?string
    {
        $name = trim($name, ' \\/');

        if (filter_var($name, FILTER_VALIDATE_IP) !== false) {
            return $name;
        }

        if (preg_match('/^[a-z0-9]([a-z0-9\-.]*[a-z0-9])?$/i', $name) !== 1) {
            return null;
        }

        return $this->dns($name)
            ?? (str_ends_with(strtolower($name), '.local') ? $this->mdns->resolve($name) : $this->mdns->resolve($name.'.local'))
            ?? $this->netbios($name);
    }

    /** Whether something answers on the SMB port. */
    public function listens(string $address): bool
    {
        $connection = @fsockopen($address, self::SMB_PORT, $code, $message, 1.0);

        if ($connection === false) {
            return false;
        }

        fclose($connection);

        return true;
    }

    protected function dns(string $name): ?string
    {
        $addresses = @gethostbynamel($name);

        return is_array($addresses) && $addresses !== [] ? $addresses[0] : null;
    }

    /** `nmblookup NAME` answers "192.168.1.20 NAME<00>" for each address. */
    protected function netbios(string $name): ?string
    {
        if (str_contains($name, '.')) {
            return null;
        }

        try {
            $result = Process::timeout(5)->run(['nmblookup', $name]);
        } catch (Throwable) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        return preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}) \S+<00>/m', $result->output(), $match) === 1 ? $match[1] : null;
    }
}
