<?php

namespace App\Providers;

use App\Models\AppSetting;
use App\Support\ConsoleOverrides;
use App\Support\DocPath;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobProcessing;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRuntimeSettings();
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
