<?php

use App\Models\ConsoleSourceFolder;
use App\Models\User;
use App\Services\NetworkService;
use App\Support\Console;
use Livewire\Livewire;

/**
 * The dashboard's share cards, once the deferred probe has answered.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('draws each protocol as the probe found it', function () {
    $this->mock(NetworkService::class)
        ->shouldReceive('status')
        ->andReturn(['smb' => true, 'ftp' => false]);

    Livewire::withoutLazyLoading()
        ->test('network-shares')
        ->assertSeeInOrder(['SMB', 'Online', 'FTP', 'Offline'])
        ->assertDontSee('Checking…');
});

it('lists the consoles in the library as shares', function () {
    $this->mock(NetworkService::class)
        ->shouldReceive('status')
        ->andReturn(['smb' => true, 'ftp' => true]);

    config()->set('settings.network.host_ip', '192.168.1.20');
    ConsoleSourceFolder::add(new Console('snes'));

    Livewire::withoutLazyLoading()
        ->test('network-shares')
        ->assertSee((new Console('snes'))->name)
        ->assertSee('192.168.1.20');
});
