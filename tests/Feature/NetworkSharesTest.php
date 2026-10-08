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
        ->assertOk()
        ->assertViewHas('status', ['smb' => true, 'ftp' => false]);
});

it('draws ps3netsrv with the PlayStation consoles it serves, each in its webMAN list, and no login', function () {
    ConsoleSourceFolder::add(new Console('ps3'));
    ConsoleSourceFolder::add(new Console('ps2'));
    ConsoleSourceFolder::add(new Console('psx'));
    config(['settings.network.host_ip' => '192.168.1.10']);

    $this->mock(NetworkService::class)
        ->shouldReceive('status')
        ->andReturn(['smb' => true, 'ftp' => true, 'ps3netsrv' => true]);

    Livewire::withoutLazyLoading()
        ->test('network-shares')
        ->assertSee('ps3netsrv')
        ->assertSee('192.168.1.10:38008 · PS3ISO')
        ->assertSee('192.168.1.10:38008 · PS2ISO')
        ->assertSee('192.168.1.10:38008 · PSXISO')
        ->assertSee('None');
});

it('leaves ps3netsrv off the panel when the share container does not run it', function () {
    config(['settings.network.ps3netsrv' => false]);

    $this->mock(NetworkService::class)
        ->shouldReceive('status')
        ->andReturn(['smb' => true, 'ftp' => true]);

    Livewire::withoutLazyLoading()
        ->test('network-shares')
        ->assertDontSee('ps3netsrv')
        ->assertDontSee('38008');
});
