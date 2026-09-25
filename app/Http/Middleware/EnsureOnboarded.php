<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AppSetting;
use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides who may be where while an install is being set up.
 *
 * No account yet: every page is the account step, which is public because
 * nobody can sign in. An account but setup unfinished — the tab was closed
 * part way — every signed-in page is the first step after it. Once setup is
 * finished the wizard itself is out of reach. A guest on a half-set-up install
 * still reaches the sign-in page, and lands in the wizard after.
 *
 * Page loads only. Livewire's update requests, assets and anything wanting
 * JSON pass through, since a redirect is no answer to them and each of those
 * belongs to a page that was already let in or sent here.
 */
class EnsureOnboarded
{
    /**
     * The public pieces the sign-in shell the wizard wears asks for.
     *
     * @var list<string>
     */
    private const PASSTHROUGH = ['favicon', 'login.backdrop'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isPageLoad($request) || $request->routeIs(...self::PASSTHROUGH)) {
            return $next($request);
        }

        $redirect = $request->user() === null
            ? $this->forGuest($request)
            : $this->forUser($request);

        return $redirect ?? $next($request);
    }

    /**
     * The account step while there is no account, and never after. The query
     * is guests' only: a signed-in request implies an account.
     */
    private function forGuest(Request $request): ?RedirectResponse
    {
        $onAccountStep = $request->routeIs('onboarding.account');

        if (User::query()->doesntExist()) {
            return $onAccountStep ? null : redirect()->route('onboarding.account');
        }

        return $onAccountStep ? redirect()->route('login') : null;
    }

    /** Inside the wizard until it is finished, and kept out of it after. */
    private function forUser(Request $request): ?RedirectResponse
    {
        $onboarded = AppSetting::enabled(AppSetting::IS_ONBOARDED);

        if ($request->routeIs('onboarding.account')) {
            return $onboarded ? redirect()->route('dashboard') : redirect()->route('onboarding.step', ['step' => 'interface']);
        }

        if ($request->routeIs('onboarding.step')) {
            return $onboarded ? redirect()->route('dashboard') : null;
        }

        return $onboarded ? null : redirect()->route('onboarding.step', ['step' => 'interface']);
    }

    /** A browser asking for a page, as opposed to a component, an asset or an API call. */
    private function isPageLoad(Request $request): bool
    {
        return $request->isMethod('GET') && ! Livewire::isLivewireRequest() && ! $request->expectsJson();
    }
}
