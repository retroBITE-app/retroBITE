<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\ScreenScraper\ScreenScraperException;
use App\Services\ScreenScraperService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ScreenScraperAccount extends Command
{
    protected $signature = 'retrobite:screenscraper:account';

    protected $description = 'Ask ScreenScraper what allowance the configured credentials actually have';

    /**
     * Say which account the provider thinks is asking, and what it may do.
     *
     * The question this answers is why the allowance is smaller than the one
     * the account is supposed to have. ScreenScraper does not reject a login
     * it does not recognise — it answers on the developer account instead,
     * with the developer account's allowance, and every game lookup looks
     * exactly the same either way. The `ssuser` block is the only place the
     * difference shows.
     */
    public function handle(ScreenScraperService $provider): int
    {
        $this->line('');
        $this->components->twoColumnDetail('<fg=gray>Sending</>', '');
        $this->components->twoColumnDetail('  ssid', $this->shown((string) config('screenscraper.user')));
        $this->components->twoColumnDetail('  sspassword', $this->passwordState((string) config('screenscraper.password')));
        $this->components->twoColumnDetail('  devid', $this->shown((string) config('screenscraper.dev_id')));
        $this->components->twoColumnDetail('  endpoint', (string) config('screenscraper.endpoint'));
        $this->line('');

        try {
            $account = $provider->account();
        } catch (ScreenScraperException $e) {
            // The message is already redacted by the service; the credentials
            // ride in the query string and must not reach a terminal either.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($account === []) {
            $this->components->error('The provider answered without an ssuser block at all.');

            return self::FAILURE;
        }

        $id = (string) Arr::get($account, 'id', '');

        $this->components->twoColumnDetail('<fg=gray>Answered as</>', '');
        $this->components->twoColumnDetail('  id', $id !== '' ? $id : '<fg=red>(none)</>');
        $this->components->twoColumnDetail('  level', (string) Arr::get($account, 'niveau', '0'));
        $this->components->twoColumnDetail('  contribution', (string) Arr::get($account, 'contribution', '0'));
        $this->components->twoColumnDetail('  threads', (string) Arr::get($account, 'maxthreads', '1'));
        $this->components->twoColumnDetail(
            '  requests today',
            Arr::get($account, 'requeststoday', '0').' / '.Arr::get($account, 'maxrequestsperday', '0'),
        );
        $this->components->twoColumnDetail(
            '  failed today',
            Arr::get($account, 'requestskotoday', '0').' / '.Arr::get($account, 'maxrequestskoperday', '0'),
        );
        $this->line('');

        return $this->verdict($id);
    }

    /**
     * Say plainly whether the login was accepted, since the numbers alone will
     * not: a smaller allowance looks like a smaller allowance either way.
     */
    private function verdict(string $id): int
    {
        $sent = (string) config('screenscraper.user');

        if ($id === '') {
            $this->components->warn(
                'The provider named no account. The request was answered on the developer '
                .'credentials alone, which is why the allowance is the developer one.'
            );

            return self::FAILURE;
        }

        if ($sent !== '' && Str::contains($sent, '@')) {
            $this->components->warn(
                'SCREENSCRAPER_USER looks like an email address. ScreenScraper wants the '
                .'login shown on your profile page, not the address you registered with — '
                ."and it answers on the developer account rather than refusing. It named \"{$id}\"; "
                .'if that is not your account, that is the reason.'
            );

            return self::FAILURE;
        }

        $this->components->info("The provider recognised the account as \"{$id}\".");

        return self::SUCCESS;
    }

    /** An unset value should read as unset rather than as an empty column. */
    private function shown(string $value): string
    {
        return $value !== '' ? $value : '<fg=red>(empty)</>';
    }

    /** Whether a password is there, never what it is. */
    private function passwordState(string $value): string
    {
        return $value !== '' ? '<fg=green>set</> ('.Str::length($value).' chars)' : '<fg=red>(empty)</>';
    }
}
