<?php

use App\Models\AppSetting;
use App\Support\MediaTypes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one-way move of the media type choice out of its own table.
 *
 * Worth testing because it runs once per install and cannot be rehearsed there:
 * a mistake silently resets what everybody chose to fetch.
 *
 * The DDL below escapes RefreshDatabase — MariaDB commits implicitly on a
 * schema change — so each test cleans up after itself rather than trusting the
 * transaction to roll back.
 */
function migration(): object
{
    return require database_path('migrations/2026_09_18_100000_move_media_types_into_app_settings.php');
}

function oldTable(): void
{
    Schema::create('media_type_preferences', function (Blueprint $table) {
        $table->id();
        $table->string('media_type')->unique();
        $table->boolean('enabled')->default(false)->index();
        $table->timestamps();
    });
}

afterEach(function () {
    Schema::dropIfExists('media_type_preferences');
    DB::table('app_settings')->where('key', 'media_types')->delete();
    AppSetting::flush();
});

it('carries the switched-on types over and drops the table', function () {
    oldTable();

    DB::table('media_type_preferences')->insert([
        ['media_type' => 'box-2D', 'enabled' => true],
        ['media_type' => 'fanart', 'enabled' => true],
        ['media_type' => 'video', 'enabled' => false],
    ]);

    migration()->up();
    // A migration runs in its own process, which reads settings afresh; this
    // one has already read them all while booting.
    AppSetting::flush();

    expect(MediaTypes::enabled())->toBe(['box-2D', 'fanart'])
        ->and(Schema::hasTable('media_type_preferences'))->toBeFalse();
});

it('keeps an empty choice empty rather than handing back the defaults', function () {
    oldTable();

    DB::table('media_type_preferences')->insert([
        ['media_type' => 'box-2D', 'enabled' => false],
    ]);

    migration()->up();
    // A migration runs in its own process, which reads settings afresh; this
    // one has already read them all while booting.
    AppSetting::flush();

    // Somebody switched everything off. That is an answer, not an absence.
    expect(MediaTypes::enabled())->toBe([]);
});

it('leaves a never-seeded install on the shipped selection', function () {
    oldTable();

    migration()->up();
    // A migration runs in its own process, which reads settings afresh; this
    // one has already read them all while booting.
    AppSetting::flush();

    expect(AppSetting::get(AppSetting::MEDIA_TYPES))->toBeNull()
        ->and(MediaTypes::enabled())->toBe(config('media_types.default_enabled'));
});

it('does nothing when the table has already gone', function () {
    AppSetting::put(AppSetting::MEDIA_TYPES, ['ss']);

    migration()->up();
    // A migration runs in its own process, which reads settings afresh; this
    // one has already read them all while booting.
    AppSetting::flush();

    expect(MediaTypes::enabled())->toBe(['ss']);
});
