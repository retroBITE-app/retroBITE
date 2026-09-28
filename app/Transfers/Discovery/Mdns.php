<?php

declare(strict_types=1);

namespace App\Transfers\Discovery;

use Socket;

/**
 * Just enough multicast DNS to ask the LAN who shares files, and to resolve a
 * `.local` name.
 *
 * A one-shot query in the sense of RFC 6762 §5.1: sent from an ordinary port,
 * so responders answer it directly instead of to the group, and nothing has
 * to join the group or bind 5353 — which a machine running Avahi already has.
 *
 * Only reaches the LAN when this container is on it: behind Docker's bridge
 * the query goes nowhere, and the answer is empty rather than an error.
 */
class Mdns
{
    private const GROUP = '224.0.0.251';

    private const PORT = 5353;

    private const TYPE_A = 1;

    private const TYPE_PTR = 12;

    private const TYPE_SRV = 33;

    /**
     * Every host advertising a service type, as name => IPv4 address.
     *
     * @return array<string, string>
     */
    public function browse(string $service = '_smb._tcp.local', float $seconds = 2.0): array
    {
        $records = $this->ask($service, self::TYPE_PTR, $seconds);
        $hosts = [];

        // Every responder answers the PTR question with its instance, and
        // adds the instance's SRV (which host) and the host's A (which
        // address) so no second question is needed. Several instances share
        // one PTR owner, so the instances are read from the SRV records.
        foreach ($records[self::TYPE_SRV] ?? [] as $instance => $target) {
            $address = $records[self::TYPE_A][$target] ?? null;

            if (str_ends_with($instance, '.'.$service) && $address !== null) {
                $hosts[$this->displayName($instance, $service, $target)] = $address;
            }
        }

        return $hosts;
    }

    /** The IPv4 address of a `.local` name, or null. */
    public function resolve(string $name, float $seconds = 1.0): ?string
    {
        $name = rtrim(strtolower($name), '.');

        return $this->ask($name, self::TYPE_A, $seconds)[self::TYPE_A][$name] ?? null;
    }

    /**
     * Send one question on every interface and gather every record in every
     * answer until the time is up.
     *
     * On every interface, not the default one: with a VPN up the multicast
     * route is the tunnel, and a question sent there reaches nobody.
     *
     * @return array<int, array<string, string>> by type, then owner name => data
     */
    private function ask(string $name, int $type, float $seconds): array
    {
        if (! function_exists('socket_create')) {
            return [];
        }

        $query = $this->query($name, $type);
        $sockets = [];

        foreach ($this->interfaces() as $interface => $address) {
            $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

            if (! $socket instanceof Socket) {
                continue;
            }

            @socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_TTL, 255);

            if ($interface !== '') {
                @socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_IF, $interface);
                @socket_bind($socket, $address, 0);
            }

            if (@socket_sendto($socket, $query, strlen($query), 0, self::GROUP, self::PORT) === false) {
                socket_close($socket);

                continue;
            }

            $sockets[] = $socket;
        }

        try {
            return $sockets === [] ? [] : $this->gather($sockets, microtime(true) + $seconds);
        } finally {
            foreach ($sockets as $socket) {
                socket_close($socket);
            }
        }
    }

    /**
     * Every interface with an IPv4 address that is not loopback, as name =>
     * address; one unnamed entry when PHP cannot list them, for the default.
     *
     * @return array<string, string>
     */
    private function interfaces(): array
    {
        $found = [];

        foreach (function_exists('net_get_interfaces') ? (net_get_interfaces() ?: []) : [] as $name => $interface) {
            foreach ($interface['unicast'] ?? [] as $unicast) {
                $address = $unicast['address'] ?? null;

                if (($unicast['family'] ?? null) === AF_INET && is_string($address) && ! str_starts_with($address, '127.')) {
                    $found[(string) $name] = $address;
                }
            }
        }

        return $found !== [] ? $found : ['' => '0.0.0.0'];
    }

    /**
     * @param  list<Socket>  $sockets
     * @return array<int, array<string, string>>
     */
    private function gather(array $sockets, float $until): array
    {
        $records = [];

        while (($left = $until - microtime(true)) > 0) {
            $read = $sockets;
            $write = $except = null;

            if (@socket_select($read, $write, $except, (int) $left, (int) (fmod($left, 1) * 1_000_000)) < 1) {
                break;
            }

            foreach ($read as $socket) {
                $packet = '';
                $from = '';
                $port = 0;

                if (@socket_recvfrom($socket, $packet, 9000, 0, $from, $port) === false) {
                    continue;
                }

                foreach ($this->parse($packet) as [$recordType, $owner, $data]) {
                    $records[$recordType][$owner] = $data;
                }
            }
        }

        return $records;
    }

    private function query(string $name, int $type): string
    {
        // ID 0, no flags, one question; class IN with the unicast-response bit.
        return pack('nnnnnn', 0, 0, 1, 0, 0, 0).$this->encodeName($name).pack('nn', $type, 0x8001);
    }

    private function encodeName(string $name): string
    {
        $encoded = '';

        // A label is at most 63 bytes; the two high bits of its length byte
        // mark a compression pointer.
        foreach (explode('.', rtrim($name, '.')) as $label) {
            $label = substr($label, 0, 63);
            $encoded .= chr(strlen($label) & 0x3F).$label;
        }

        return $encoded."\0";
    }

    /**
     * The A, PTR and SRV records in a response, answers and additionals alike.
     *
     * @return list<array{int, string, string}> type, owner, data
     */
    private function parse(string $packet): array
    {
        if (strlen($packet) < 12) {
            return [];
        }

        /** @var array{flags: int, qd: int, an: int, ns: int, ar: int} $header */
        $header = unpack('nid/nflags/nqd/nan/nns/nar', $packet);

        // Responses only.
        if (($header['flags'] & 0x8000) === 0) {
            return [];
        }

        $offset = 12;

        for ($i = 0; $i < $header['qd']; $i++) {
            $this->readName($packet, $offset);
            $offset += 4;
        }

        $records = [];
        $count = $header['an'] + $header['ns'] + $header['ar'];

        for ($i = 0; $i < $count && $offset + 10 <= strlen($packet); $i++) {
            $owner = strtolower($this->readName($packet, $offset));

            /** @var array{type: int, length: int} $fixed */
            $fixed = unpack('ntype/nclass/Nttl/nlength', substr($packet, $offset, 10));
            $offset += 10;
            $start = $offset;
            $offset += $fixed['length'];

            // SRV data opens with priority, weight and port before the target.
            $at = $fixed['type'] === self::TYPE_SRV ? $start + 6 : $start;

            $data = match ($fixed['type']) {
                self::TYPE_A => $fixed['length'] === 4 ? (string) inet_ntop(substr($packet, $start, 4)) : null,
                self::TYPE_PTR, self::TYPE_SRV => strtolower($this->readName($packet, $at)),
                default => null,
            };

            if ($data !== null && $data !== '') {
                $records[] = [$fixed['type'], $owner, $data];
            }
        }

        return $records;
    }

    /** A name at the offset, following compression pointers; advances the offset past it. */
    private function readName(string $packet, int &$offset): string
    {
        $labels = [];
        $position = $offset;
        $jumped = false;

        for ($guard = 0; $guard < 128 && $position < strlen($packet); $guard++) {
            $length = ord($packet[$position]);

            if ($length === 0) {
                $position++;

                break;
            }

            if (($length & 0xC0) === 0xC0) {
                if (! $jumped) {
                    $offset = $position + 2;
                }

                $jumped = true;
                $position = (($length & 0x3F) << 8) | ord($packet[$position + 1] ?? "\0");

                continue;
            }

            $labels[] = substr($packet, $position + 1, $length);
            $position += $length + 1;
        }

        if (! $jumped) {
            $offset = $position;
        }

        return implode('.', $labels);
    }

    /** "BATOCERA._smb._tcp.local" as BATOCERA; the host name when the instance has none. */
    private function displayName(string $instance, string $service, string $host): string
    {
        $name = substr($instance, 0, -strlen($service) - 1);

        return $name !== '' ? $name : (string) preg_replace('/\.local$/', '', $host);
    }
}
