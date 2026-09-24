<?php

use App\Models\ConsoleSourceFolder;
use App\Services\NetworkService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Defer;
use Livewire\Component;

/**
 * The dashboard's SMB and FTP status cards.
 *
 * A component rather than part of the dashboard's own render because the probe
 * behind it opens a socket per protocol with a timeout each, and a share
 * container that is down made the whole page wait out both. Deferred rather
 * than lazy: it sits at the foot of the page, and lazy would not start the
 * probe until somebody scrolled there.
 */
new #[Defer] class extends Component
{
    /**
     * The cards with the probe's answer.
     *
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [...$this->details(), 'status' => app(NetworkService::class)->status()];
    }

    /**
     * The same cards before the probe has run, so the page does not jump when it lands.
     */
    public function placeholder(): View
    {
        return view('partials.network-shares', [...$this->details(), 'status' => null]);
    }

    /**
     * Everything the cards draw that costs no network round trip.
     *
     * @return array<string, mixed>
     */
    private function details(): array
    {
        return [
            'hostIp' => config('settings.network.host_ip'),
            'shareUser' => config('settings.network.username'),
            'shares' => ConsoleSourceFolder::consoles(),
        ];
    }
}; ?>

@include('partials.network-shares')
