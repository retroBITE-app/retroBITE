<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\NetworkService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class NetworkController
{
    public function __construct(private NetworkService $network) {}

    public function status(Request $request, Response $response): Response
    {
        $data = $this->network->status();
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
