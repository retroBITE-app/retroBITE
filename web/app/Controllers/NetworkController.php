<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Services\NetworkService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class NetworkController
{
    public function __construct(
        private NetworkService $network,
    ) {}

    /**
     * Whether the SMB and FTP services are currently reachable.
     */
    public function status(Request $request, Response $response): Response
    {
        return ApiResponse::json($response, $this->network->status());
    }
}
