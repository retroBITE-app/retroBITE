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
    public function index(Request $_request, Response $response): Response
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
     * Persist a whole-item override.
     * Body: JSON of the full entry. Validates against the group's schema.
     */
    public function save(Request $request, Response $response, array $args): Response
    {
        $group = (string) Arr::get($args, 'group');
        $key   = (string) Arr::get($args, 'key');

        if (!Registry::has($group)) {
            return $this->jsonError($response, 'Unknown group', 404);
        }

        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $key)) {
            return $this->jsonError($response, 'Invalid key', 422);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->jsonError($response, 'Body must be JSON object', 422);
        }

        $normalised = Registry::normalise($group, $body);
        $errors     = Registry::validate($group, $normalised);

        if ($errors !== []) {
            return $this->json($response, ['error' => 'Validation failed', 'errors' => $errors], 422);
        }

        $this->settings->upsert($group, $key, $normalised);

        return $this->json($response, ['status' => 'ok', 'value' => $normalised]);
    }

    /**
     * Remove a previously-saved override; config() falls back to the file default.
     */
    public function reset(Request $_request, Response $response, array $args): Response
    {
        $group = (string) Arr::get($args, 'group');
        $key   = (string) Arr::get($args, 'key');

        if (!Registry::has($group)) {
            return $this->jsonError($response, 'Unknown group', 404);
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
