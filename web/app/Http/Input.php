<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Typed reads over a request's parsed body, query string and route arguments.
 *
 * Every endpoint previously re-derived its own casts and guards inline; the
 * upload handler alone had thirty consecutive lines of them.
 */
final class Input
{
    private function __construct(
        private array $data,
    ) {}

    /**
     * Read from the parsed body, treating a non-array body as empty.
     */
    public static function body(ServerRequestInterface $request): self
    {
        $body = $request->getParsedBody();

        return new self(is_array($body) ? $body : []);
    }

    /**
     * Read from the query string.
     */
    public static function query(ServerRequestInterface $request): self
    {
        return new self($request->getQueryParams());
    }

    /**
     * Read from Slim's route arguments.
     */
    public static function args(array $args): self
    {
        return new self($args);
    }

    /**
     * A trimmed string, or $default when absent or blank.
     */
    public function string(string $key, string $default = ''): string
    {
        $value = Arr::get($this->data, $key);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : $default;
    }

    /**
     * An integer, or $default when absent or not numeric.
     */
    public function integer(string $key, int $default = 0): int
    {
        $value = Arr::get($this->data, $key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * A list, or [] when the key holds anything else.
     */
    public function list(string $key): array
    {
        $value = Arr::get($this->data, $key);

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Is the key present at all, whatever its value?
     */
    public function has(string $key): bool
    {
        return Arr::has($this->data, $key);
    }

    /**
     * A comma-separated string split into trimmed, non-empty parts.
     *
     * @return string[]
     */
    public function commaSeparated(string $key): array
    {
        $raw = $this->string($key);

        if ($raw === '') {
            return [];
        }

        return array_values(Arr::where(
            Arr::map(explode(',', $raw), fn(string $part): string => trim($part)),
            fn(string $part): bool => $part !== '',
        ));
    }

}
