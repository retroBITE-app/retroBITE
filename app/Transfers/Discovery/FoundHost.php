<?php

declare(strict_types=1);

namespace App\Transfers\Discovery;

/** A machine on the network that answered for SMB. */
final class FoundHost
{
    public function __construct(
        public readonly string $name,
        public readonly string $address,
        /** mdns | dns | netbios — which question it answered. */
        public readonly string $via,
    ) {}

    /** @return array{name: string, address: string, via: string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'address' => $this->address, 'via' => $this->via];
    }
}
