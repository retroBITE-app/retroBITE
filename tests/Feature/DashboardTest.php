<?php

use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\User;
use App\Services\NetworkService;
use App\Support\Console;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

test('guests are redirected to the login page', function () {
    // An install with no account answers every page with onboarding.
    User::factory()->create();

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('the newest game is the hero and is not repeated in the cards beside it', function () {
    foreach (['Oldest', 'Second', 'Third', 'Fourth', 'Newest'] as $title) {
        Game::factory()->forConsole('snes')->create(['title' => $title, 'slug' => Str::slug($title)]);
    }

    $this->actingAs(User::factory()->create());

    $content = $this->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder(['Newest', 'Fourth', 'Third', 'Second'])
        ->assertDontSee('Oldest')
        ->getContent();

    expect(substr_count($content, 'Newest'))->toBe(1);
});

test('it counts identified games and the files on disk, in that order', function () {
    $root = sys_get_temp_dir().'/retrobite-dashboard-'.Str::random(8);
    File::ensureDirectoryExists($root.'/snes');
    config()->set('settings.games_path', $root);

    foreach (['A.sfc', 'B.sfc', 'C.sfc'] as $filename) {
        File::put($root.'/snes/'.$filename, 'x');
    }

    ConsoleSourceFolder::add(new Console('snes'));

    // One identified, one still waiting on the provider: two rows, one game.
    Game::factory()->forConsole('snes')->matched()->create(['title' => 'One', 'slug' => 'one']);
    Game::factory()->forConsole('snes')->create(['title' => 'Two', 'slug' => 'two']);

    $this->actingAs(User::factory()->create());

    try {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Games', '1', '1 still unmatched', 'Files', '3', 'across 1 console']);
    } finally {
        File::deleteDirectory($root);
    }
});

test('the page does not wait on the share probe', function () {
    $this->mock(NetworkService::class)->shouldNotReceive('status');

    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Network shares')
        ->assertSee('Checking…');
});

test('it greets by the hour where the owner is, and by name', function () {
    $this->actingAs(User::factory()->create(['username' => 'rakma']));

    // 16:30 UTC is still the afternoon there, and already evening in Sweden.
    $this->travelTo(CarbonImmutable::parse('2026-09-27 16:30:00', 'UTC'));

    config()->set('app.timezone', 'UTC');
    $this->get(route('dashboard'))->assertOk()->assertSee('Good afternoon, rakma');

    config()->set('app.timezone', 'Europe/Stockholm');
    $this->get(route('dashboard'))->assertOk()->assertSee('Good evening, rakma');
});

test('it greets by display name where there is no username', function () {
    $this->actingAs(User::factory()->create(['username' => null, 'name' => 'Player One']));

    $this->get(route('dashboard'))->assertOk()->assertSee(', Player One');
});
