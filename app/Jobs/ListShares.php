<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TransferFailure;
use App\Events\SystemUpdated;
use App\Exceptions\TransferFailed;
use App\Support\LiveUpdates;
use App\Transfers\Discovery\ShareDiscovery;
use App\Transfers\Smb\ShareClient;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Ask one host which shares it offers, with the credentials somebody typed,
 * for the destinations page to offer as a list.
 *
 * Encrypted on the queue, because it carries a password. The answer — the
 * address the name resolved to, the shares, or why not — is left in the cache
 * under the page's token.
 */
class ListShares implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(
        public readonly string $token,
        public readonly string $host,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
    ) {}

    public static function cacheKey(string $token): string
    {
        return 'share-listing:'.$token;
    }

    public function handle(ShareDiscovery $discovery, ShareClient $client): void
    {
        $address = $discovery->resolve($this->host);

        if ($address === null) {
            $this->answer(['host' => $this->host, 'address' => null, 'shares' => [], 'failure' => TransferFailure::Unreachable->value]);

            return;
        }

        try {
            $shares = $client->shares($address, $this->username, $this->password);
        } catch (TransferFailed $e) {
            $this->answer(['host' => $this->host, 'address' => $address, 'shares' => [], 'failure' => $e->reason->value]);

            return;
        }

        $this->answer(['host' => $this->host, 'address' => $address, 'shares' => $shares, 'failure' => null]);
    }

    public function failed(): void
    {
        $this->answer(['host' => $this->host, 'address' => null, 'shares' => [], 'failure' => TransferFailure::Unreachable->value]);
    }

    /** @param  array{host: string, address: ?string, shares: list<string>, failure: ?string}  $answer */
    private function answer(array $answer): void
    {
        Cache::put(self::cacheKey($this->token), $answer, now()->addMinutes(10));

        LiveUpdates::system(SystemUpdated::DISCOVERY);
    }
}
