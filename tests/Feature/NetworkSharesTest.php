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
        ->assertSee('none')
        ->assertSee('Read-only');
});

it('says the host once, folds each share list under its row, and explains a protocol that is down', function () {
    ConsoleSourceFolder::add(new Console('ps2'));
    config(['settings.network.host_ip' => '192.168.1.10']);

    $this->mock(NetworkService::class)
        ->shouldReceive('status')
        ->andReturn(['smb' => false, 'ftp' => true, 'ps3netsrv' => true]);

    Livewire::withoutLazyLoading()
        ->test('network-shares')
        ->assertSeeHtml('x-data="shareRows"')
        ->assertSeeHtml("x-show=\"isOpen('smb')\"")
        ->assertSee('1 share')
        ->assertSee('Not answering on 445. Is the share container running?')
        ->assertDontSee('Not answering on 21.');
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

it('warns on the ps3netsrv card when a console is kept in a folder ps3netsrv does not serve', function () {
    ConsoleSourceFolder::add(new Console('ps3'), 'ps3-isos');

    $this->mock(NetworkService::class)->shouldReceive('status')->andReturn(['smb' => true, 'ftp' => true, 'ps3netsrv' => true]);

    Livewire::withoutLazyLoading()
        ->test('network-shares')
        ->assertSee('Its games are in ps3-isos, but ps3netsrv serves ps3')
        ->assertDontSee('38008 · PS3ISO');
});

it('says on the ps3netsrv card who may connect', function (string $whitelist, string $says) {
    config(['settings.network.ps3netsrv_whitelist' => $whitelist]);

    $this->mock(NetworkService::class)->shouldReceive('status')->andReturn(['smb' => true, 'ftp' => true, 'ps3netsrv' => true]);

    Livewire::withoutLazyLoading()->test('network-shares')->assertSee($says);
})->with([
    'nobody named' => ['', 'Readable by everyone on the network'],
    'a whitelist' => ['192.168.1.*', '192.168.1.* only'],
    'a range it cannot read' => ['192.168.1.0/24', 'so it is not started'],
]);
