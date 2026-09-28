<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\SystemUpdated;
use App\Support\LiveUpdates;
use App\Transfers\Discovery\FoundHost;
use App\Transfers\Discovery\ShareDiscovery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Look for machines sharing files on the network, for the destinations page.
 *
 * A few seconds of waiting on multicast answers and port checks, which is why
 * it is a job: no request waits on the network. The answer is left in the
 * cache under the page's token, and the page is told to read it.
 */
class DiscoverShares implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    /**
     * @param  list<string>  $hints  the address the page was opened on, and the
     *                               browser's: the LAN, when the container
     *                               cannot tell which network that is
     */
    public function __construct(
        public readonly string $token,
        public readonly array $hints = [],
    ) {}

    public static function cacheKey(string $token): string
    {
        return 'share-discovery:'.$token;
    }

    public function handle(ShareDiscovery $discovery): void
    {
        $hosts = array_map(fn (FoundHost $host): array => $host->toArray(), $discovery->find($this->hints));

        Cache::put(self::cacheKey($this->token), [
            'hosts' => $hosts,
            // Which networks were scanned, so the page can say when none was.
            'subnets' => array_map(fn (string $prefix): string => $prefix.'.0/24', $discovery->subnets($this->hints)),
        ], now()->addMinutes(10));

        LiveUpdates::system(SystemUpdated::DISCOVERY);
    }

    public function failed(): void
    {
        Cache::put(self::cacheKey($this->token), ['hosts' => [], 'subnets' => []], now()->addMinutes(10));

        LiveUpdates::system(SystemUpdated::DISCOVERY);
    }
}
