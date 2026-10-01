<?php

namespace App\Providers;

use App\Events\SystemUpdated;
use App\Models\AppSetting;
use App\Support\ConsoleOverrides;
use App\Support\DocPath;
use App\Support\LiveUpdates;
use App\Transfers\Smb\ShareClient;
use App\Transfers\Smb\SmbclientShareClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // DocPath takes its root as an argument rather than reading config
        $this->app->singleton(DocPath::class, function (): DocPath {
            return new DocPath((string) config('settings.docs_path'));
        });

        // Other machines' shares, for transfers. The tests bind a folder instead.
        $this->app->bind(ShareClient::class, SmbclientShareClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRuntimeSettings();
        $this->configureProxies();
    }

    /**
     * Believe the proxies config/app.php names, so an https request behind one
     * writes https links. See `app.trusted_proxies` for why the default is
     * everyone in local development and nobody elsewhere.
     */
    protected function configureProxies(): void
    {
        $proxies = config('app.trusted_proxies');

        if (! is_string($proxies) || $proxies === '') {
            return;
        }

        TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
    }

    /**
     * Wire the settings table into the places that read it implicitly.
     */
    protected function configureRuntimeSettings(): void
    {
        /**
         * Four views ask whether the CRT overlay is on. A directive keeps the
         * model out of the markup and reads as what it means.
         */
        Blade::if('scanlines', fn (): bool => AppSetting::enabled(AppSetting::UI_SCANLINES));

        // Console config overrides, merged over the shipped files.
        ConsoleOverrides::apply();

        /** AppSetting memoises for the length of a request, and a queue worker
         * is one process for many jobs — without this, a setting changed in the
         * interface would never reach a worker already running.
         */
        Event::listen(function (JobProcessing $event): void {
            AppSetting::flush();
            ConsoleOverrides::apply();
        });

        $this->configureLiveUpdates();
    }

    /**
     * Tell the sidebar the queues moved, for every job there is.
     *
     * One listener here rather than a call at each dispatch: it hears jobs
     * queued from the command line and the scheduler as well as from the
     * interface, which the page-side nudges it replaces never could. Every
     * edge the sidebar counts — waiting, running, done, failed, back in the
     * queue — sends one; the browser folds a burst into one re-render a second.
     *
     * Except queuing, which comes in loops: a backfill queues thousands in a
     * second, and each was a broadcast Reverb had to take. Those are one at
     * the start of the burst and one after it (LiveUpdates::soon()).
     */
    protected function configureLiveUpdates(): void
    {
        Event::listen(JobQueued::class, fn (): null => LiveUpdates::soon(SystemUpdated::ACTIVITY));

        Event::listen(
            [JobProcessing::class, JobProcessed::class, JobFailed::class, JobReleasedAfterException::class],
            fn (): null => LiveUpdates::system(SystemUpdated::ACTIVITY),
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
