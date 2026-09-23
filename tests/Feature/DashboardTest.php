<?php

use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\User;
use App\Support\Console;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
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
