<?php

declare(strict_types=1);

namespace App\Services;

class NetworkService
{
    public function checkPort(string $host, int $port, float $timeout = 1.5): bool
    {
        try {
            $conn = @fsockopen($host, $port, $errno, $errstr, $timeout);
            if (is_resource($conn)) {
                fclose($conn);
                return true;
            }
            throw new \Exception("Connection failed: $errstr ($errno)");
        } catch (\Exception $e) {
            logger()->error("Port check failed for $host:$port - " . $e->getMessage());
            return false;
        }
    }

    public function status(): array
    {
        $host = config('settings.network.host_ip');
        return [
            'smb' => $this->checkPort($host, 445),
            'ftp' => $this->checkPort($host, 21),
        ];
    }
}
