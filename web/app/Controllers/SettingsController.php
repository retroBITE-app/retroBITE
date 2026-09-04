<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Enums\ResponseStatus;
use App\Http\ApiResponse;
use App\Http\Input;
use App\Inertia\Inertia;
use App\Services\AboutService;
use App\Services\SettingsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SettingsController
{
    public function __construct(
        private SettingsService $settings,
        private AboutService $about,
    ) {}

    /**
     * Settings page: tabs per overridable group, plus the About tab's content.
     */
    public function index(Request $request, Response $response): Response
    {
        return Inertia::render($request, $response, 'Settings/Index', [
            'groups' => $this->settings->groups(),
            'about'  => $this->about->payload(),
        ]);
    }

    /**
     * Persist an override for one existing item.
     */
    public function save(Request $request, Response $response, array $args): Response
    {
        $input = Input::args($args);

        $value = $this->settings->save(
            $input->string('group'),
            $input->string('key'),
            $request->getParsedBody(),
        );

        return ApiResponse::status($response, ResponseStatus::Ok, ['value' => $value]);
    }

    /**
     * Remove a saved override so config() falls back to the file default.
     */
    public function reset(Request $request, Response $response, array $args): Response
    {
        $input = Input::args($args);

        return ApiResponse::status($response, ResponseStatus::Ok, [
            'deleted' => $this->settings->reset($input->string('group'), $input->string('key')),
        ]);
    }
}
