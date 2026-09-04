<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\DomainException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\CallableResolverInterface;
use Slim\Handlers\ErrorHandler as SlimErrorHandler;
use Throwable;

/**
 * Single exit point for unhandled throwables: domain failures render as their own
 * status and code, everything else falls through to Slim's renderer.
 */
final class ErrorRenderer
{
    public function __construct(
        private CallableResolverInterface $callableResolver,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    /**
     * Render a throwable, logging anything that is not an expected domain failure.
     */
    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
    ): ResponseInterface {
        if ($exception instanceof DomainException) {
            return $this->renderDomainFailure($request, $exception);
        }

        logger()->error($exception->getMessage(), [
            'exception' => get_class($exception),
            'file'      => $exception->getFile(),
            'line'      => $exception->getLine(),
            'url'       => (string) $request->getUri(),
        ]);

        $handler = new SlimErrorHandler($this->callableResolver, $this->responseFactory);

        return $handler->__invoke($request, $exception, $displayErrorDetails, true, true);
    }

    /**
     * Domain failures are expected, so they are logged at debug and answered with
     * the status the exception itself declares.
     */
    private function renderDomainFailure(
        ServerRequestInterface $request,
        DomainException $exception,
    ): ResponseInterface {
        logger()->debug('Request rejected', [
            'code'    => $exception->code(),
            'message' => $exception->getMessage(),
            'url'     => (string) $request->getUri(),
        ]);

        $response = $this->responseFactory->createResponse();

        if ($this->wantsJson($request)) {
            return ApiResponse::fromException($response, $exception);
        }

        return $response->withStatus($exception->status());
    }

    /**
     * Would this caller rather have JSON than a page?
     */
    private function wantsJson(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine('X-Inertia') !== ''
            || str_contains($request->getHeaderLine('Accept'), 'application/json');
    }
}
