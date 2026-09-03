<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Enums\ResponseStatus;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\ApiResponse;
use App\Http\Input;
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
                'slug'      => $slug,
                'label'     => Registry::labelFor($slug),
                'schema'    => Registry::schemaFor($slug),
                'items'     => $this->itemsFor($slug),
                'overrides' => array_keys($overrides[$slug] ?? []),
            ])
            ->values()
            ->all();

        return Inertia::render($request, $response, 'Settings/Index', ['groups' => $groups]);
    }

    /**
     * Persist an override for one existing item. Body is JSON of the entry's schema
     * fields; unknown fields are dropped and a key that does not already exist is
     * rejected rather than creating an entry missing its unexposed fields.
     */
    public function save(Request $request, Response $response, array $args): Response
    {
        [$group, $key] = $this->target($args, mustExist: true);

        $body = $request->getParsedBody();

        if (!is_array($body)) {
            throw ValidationException::because('Body must be a JSON object');
        }

        $normalized = Registry::normalize($group, $body);
        $errors     = Registry::validate($group, $normalized);

        if ($errors !== []) {
            throw ValidationException::fields($errors);
        }

        $this->settings->upsert($group, $key, $normalized);

        return ApiResponse::status($response, ResponseStatus::Ok, ['value' => $normalized]);
    }

    /**
     * Remove a saved override so config() falls back to the file default. Unlike
     * save(), the item need not still exist — orphaned rows must stay deletable.
     */
    public function reset(Request $request, Response $response, array $args): Response
    {
        [$group, $key] = $this->target($args, mustExist: false);

        return ApiResponse::status($response, ResponseStatus::Ok, [
            'deleted' => $this->settings->delete($group, $key),
        ]);
    }

    /**
     * Validate and return the {group, key} pair a request addresses.
     *
     * @return array{0: string, 1: string}
     * @throws NotFoundException|ValidationException
     */
    private function target(array $args, bool $mustExist): array
    {
        $input = Input::args($args);
        $group = $input->string('group');
        $key   = $input->string('key');

        if (!Registry::has($group)) {
            throw NotFoundException::settingsGroup($group);
        }

        if (!Registry::isValidKey($key)) {
            throw ValidationException::because('Invalid key');
        }

        if ($mustExist && !Registry::hasItem($group, $key)) {
            throw NotFoundException::settingsItem($group, $key);
        }

        return [$group, $key];
    }

    /**
     * Merged items for a group, keyed by item key and limited to schema fields so
     * internal keys never reach the browser.
     *
     * @return array<string, array<string, mixed>>
     */
    private function itemsFor(string $group): array
    {
        $schema = array_keys(Registry::schemaFor($group));

        return Collection::make((array) config($group, []))
            ->filter(fn(mixed $value) => is_array($value))
            ->map(fn(array $value) => Arr::only($value, $schema))
            ->all();
    }
}
