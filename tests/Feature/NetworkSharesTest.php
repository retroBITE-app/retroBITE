<?php

use App\Models\User;
use App\Services\NetworkService;
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
