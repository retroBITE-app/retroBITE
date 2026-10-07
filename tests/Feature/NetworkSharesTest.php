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

it('draws ps3netsrv with the PlayStation 3 alone and no login', function () {
    ConsoleSourceFolder::add(new Console('ps3'));
    ConsoleSourceFolder::add(new Console('ps2'));
    config(['settings.network.host_ip' => '192.168.1.10']);

    $this->mock(NetworkService::class)
        ->shouldReceive('status')
        ->andReturn(['smb' => true, 'ftp' => true, 'ps3netsrv' => true]);

    Livewire::withoutLazyLoading()
        ->test('network-shares')
        ->assertSee('ps3netsrv')
        ->assertSee('192.168.1.10:38008')
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
