<?php

declare(strict_types=1);

namespace App\Transfers\Endpoints;

use App\Enums\TransferFailure;
use App\Exceptions\TransferFailed;
use App\Models\Destination;
use App\Support\Console;
use App\Support\LibraryPath;
use App\Transfers\Smb\ShareClient;
use Illuminate\Support\Str;

/**
 * Turns a location's endpoint key into the endpoint, once per key per run, so
 * a share is connected to once however many files go to it.
 */
final class Endpoints
{
    /** @var array<string, Endpoint> */
    private array $resolved = [];

    public function __construct(private readonly ShareClient $shares) {}

    /** @throws TransferFailed */
    public function resolve(string $key): Endpoint
    {
        return $this->resolved[$key] ??= $this->make($key);
    }

    /** @throws TransferFailed */
    private function make(string $key): Endpoint
    {
        if ($key === 'staging') {
            return new StagingEndpoint(new LibraryPath);
        }

        if ($key === 'media') {
            return new MediaEndpoint;
        }

        if (Str::startsWith($key, 'library:')) {
            $console = Console::tryFrom(Str::after($key, 'library:'));

            if ($console !== null) {
                return new LibraryEndpoint($console, new LibraryPath);
            }
        }

        if (Str::startsWith($key, 'destination:')) {
            $destination = Destination::query()->find((int) Str::after($key, 'destination:'));

            if ($destination !== null) {
                return new ShareEndpoint($destination, $this->shares);
            }
        }

        throw TransferFailed::because(TransferFailure::Rejected, $key);
    }
}
