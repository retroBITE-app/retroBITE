<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Inertia\Inertia;
use App\Repositories\SettingRepository;
use App\Support\Registry;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SettingsController
{
    public function __construct(
        private SettingRepository $settings,
    ) {}

    /**
     * Settings page: tabs per overridable group, each with its current item values.
     */
    public function index(Request $request, Response $response): Response
    {
        $overrides = $this->settings->asMap();

        $groups = Collection::make(Registry::groups())
            ->map(fn(string $slug) => [
                'slug'    => $slug,
                'label'   => Registry::labelFor($slug),
                'schema'  => Registry::schemaFor($slug),
                'items'   => $this->itemsFor($slug),
                'overrides' => array_keys($overrides[$slug] ?? []),
            ])
            ->values()
            ->all();

        return Inertia::render($response, 'Settings/Index', [
            'groups' => $groups,
        ]);
    }

    /**
     * Persist an override for one existing item. Body is JSON of the entry's schema
     * fields; unknown fields are dropped and a key that does not already exist is
     * rejected rather than creating an entry missing its unexposed fields.
     */
    public function save(Request $request, Response $response, array $args): Response
    {
        $group = (string) Arr::get($args, 'group');
        $key   = (string) Arr::get($args, 'key');

        if (!Registry::has($group)) {
            return $this->jsonError($response, 'Unknown group', 404);
        }

        if (!Registry::isValidKey($key)) {
            return $this->jsonError($response, 'Invalid key', 422);
        }

        if (!Registry::hasItem($group, $key)) {
            return $this->jsonError($response, 'Unknown item', 404);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->jsonError($response, 'Body must be JSON object', 422);
        }

        $normalized = Registry::normalize($group, $body);
        $errors     = Registry::validate($group, $normalized);

        if ($errors !== []) {
            return $this->json($response, ['error' => 'Validation failed', 'errors' => $errors], 422);
        }

        $this->settings->upsert($group, $key, $normalized);

        return $this->json($response, ['status' => 'ok', 'value' => $normalized]);
    }

    /**
     * Remove a saved override so config() falls back to the file default. Unlike
     * save(), the item need not still exist — orphaned rows must stay deletable.
     */
    public function reset(Request $request, Response $response, array $args): Response
    {
        $group = (string) Arr::get($args, 'group');
        $key   = (string) Arr::get($args, 'key');

        if (!Registry::has($group)) {
            return $this->jsonError($response, 'Unknown group', 404);
        }

        if (!Registry::isValidKey($key)) {
            return $this->jsonError($response, 'Invalid key', 422);
        }

        $deleted = $this->settings->delete($group, $key);

        return $this->json($response, ['status' => 'ok', 'deleted' => $deleted]);
    }

    /**
     * Merged items for a group, keyed by item key.
     * @return array<string, array<string, mixed>>
     */
    private function itemsFor(string $group): array
    {
        // config($group) returns the merged file+override view thanks to the config() hook.
        $all = (array) config($group, []);

        // Limit output to fields in the schema (drops internal/unexposed keys).
        $schema = array_keys(Registry::schemaFor($group));

        $out = [];
        foreach ($all as $itemKey => $value) {
            if (!is_array($value)) {
                continue;
            }
            $out[(string) $itemKey] = Arr::only($value, $schema);
        }

        return $out;
    }

    private function json(Response $response, array $body, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function jsonError(Response $response, string $message, int $status): Response
    {
        return $this->json($response, ['error' => $message], $status);
    }
}
