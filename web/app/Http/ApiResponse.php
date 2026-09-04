<?php

declare(strict_types=1);

namespace App\Http;

use App\Enums\ResponseStatus;
use App\Exceptions\DomainException;
use Psr\Http\Message\ResponseInterface;

/**
 * The one place JSON responses are shaped.
 *
 * Replaces three private json()/jsonError() pairs whose encoding flags
 * disagreed and five inlined json_encode calls that omitted
 * JSON_THROW_ON_ERROR — which turned a single non-UTF-8 ROM filename into a
 * TypeError on write.
 */
final class ApiResponse
{
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * A JSON body with an explicit status.
     */
    public static function json(ResponseInterface $response, array $body, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($body, self::FLAGS));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * A bare `{"status": ...}` acknowledgement, optionally with extra fields.
     */
    public static function status(
        ResponseInterface $response,
        ResponseStatus $status = ResponseStatus::Ok,
        array $extra = [],
    ): ResponseInterface {
        return self::json($response, ['status' => $status->value, ...$extra]);
    }

    /**
     * An error body carrying a message and an optional machine-readable code.
     */
    public static function error(
        ResponseInterface $response,
        string $message,
        int $status,
        ?string $code = null,
        array $errors = [],
    ): ResponseInterface {
        $body = ['error' => $message];

        if ($code !== null) {
            $body['code'] = $code;
        }

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return self::json($response, $body, $status);
    }

    /**
     * Render a domain failure using the status and code it declares.
     */
    public static function fromException(ResponseInterface $response, DomainException $e): ResponseInterface
    {
        return self::error(
            $response,
            $e->getMessage(),
            $e->status(),
            $e->code(),
            $e->errors(),
        );
    }
}
