<?php

use App\Jobs\PruneGame;
use App\Models\AppSetting;
use App\Models\Game;
use App\Models\GameFile;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * Settings → Library: how long a game may be without its files, whether that
 * is enforced every night, and a button to do it now.
 */

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('shows the page under its own tab', function () {
    $this->get(route('library.edit'))
        ->assertOk()
        ->assertSee(__('Remove games whose ROMs are gone'));
});

it('leaves the nightly prune off until someone turns it on', function () {
    expect(AppSetting::enabled(AppSetting::PRUNE_MISSING_AUTO))->toBeFalse();

    Livewire::test('settings.library')->assertSet('auto', false);
});

it('saves the days and the nightly switch', function () {
    Livewire::test('settings.library')
        ->set('days', '14')
        ->set('auto', true)
        ->call('save')
        ->assertHasNoErrors();

    AppSetting::flush();

    expect(AppSetting::get(AppSetting::PRUNE_MISSING_AFTER_DAYS))->toBe(14)
        ->and(AppSetting::enabled(AppSetting::PRUNE_MISSING_AUTO))->toBeTrue();
});

it('refuses no days, and anything that is not a number of them', function (mixed $days) {
    Livewire::test('settings.library')
        ->set('days', $days)
        ->call('save')
        ->assertHasErrors('days');
})->with(['0', '-3', 'soon', '']);

it('queues a prune at the days typed when Prune now is pressed', function () {
    $game = Game::factory()->forConsole('snes')->create();
    Queue::fake();

    Livewire::test('settings.library')
        ->set('days', '7')
        ->call('pruneNow');

    Queue::assertPushed(PruneGame::class, function (PruneGame $job) use ($game): bool {
        return $job->gameId === $game->id && $job->days === 7;
    });
});

it('counts the games and the missing ROMs a prune would remove now', function () {
    $gone = Game::factory()->forConsole('snes')->create();
    GameFile::factory()->for($gone)->create(['missing_since' => now()->subDays(40)]);

    $kept = Game::factory()->forConsole('snes')->create();
    GameFile::factory()->for($kept)->create(['path' => 'snes/kept.sfc']);

    $page = Livewire::test('settings.library');

    expect($page->instance()->wouldGo)->toBe(['games' => 1, 'files' => 1]);
});
