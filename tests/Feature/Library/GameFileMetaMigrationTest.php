<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The move of disc facts into game_file_meta, run again after being cut short.
 *
 * In a database of its own: MariaDB commits every DDL statement, so running
 * this migration on the test database would end RefreshDatabase's transaction
 * and leave its tables changed under every later test.
 */
beforeEach(function () {
    $this->database = 'retrobite_migration_'.bin2hex(random_bytes(4));

    config([
        'database.connections.scratch_server' => [...config('database.connections.mariadb'), 'database' => 'information_schema'],
        'database.connections.scratch' => [...config('database.connections.mariadb'), 'database' => $this->database],
    ]);
    DB::connection('scratch_server')->statement("CREATE DATABASE `{$this->database}`");

    $this->default = DB::getDefaultConnection();
    DB::setDefaultConnection('scratch');

    Schema::create('game_files', function (Blueprint $table) {
        $table->id();
        $table->string('license_id', 16)->nullable()->index();
        $table->string('video_mode', 4)->nullable();
        $table->boolean('encrypted')->nullable();
        $table->string('disc_key', 32)->nullable();
    });

    DB::table('game_files')->insert([
        ['license_id' => 'BLUS30538', 'video_mode' => null, 'encrypted' => true, 'disc_key' => str_repeat('ab', 16)],
        ['license_id' => 'SLES_503.86', 'video_mode' => 'PAL', 'encrypted' => null, 'disc_key' => null],
        ['license_id' => null, 'video_mode' => null, 'encrypted' => null, 'disc_key' => null],
    ]);

    $this->migration = fn (): Migration => require base_path('database/migrations/2026_10_08_100000_create_game_file_meta_table.php');
});

afterEach(function () {
    DB::setDefaultConnection($this->default);
    DB::purge('scratch');
    DB::connection('scratch_server')->statement("DROP DATABASE IF EXISTS `{$this->database}`");
    DB::purge('scratch_server');
});

it('moves every fact across once and takes them off game_files', function () {
    ($this->migration)()->up();

    expect(DB::table('game_file_meta')->orderBy('game_file_id')->pluck('license_id')->all())->toBe(['BLUS30538', 'SLES_503.86'])
        ->and(DB::table('game_file_meta')->where('game_file_id', 1)->value('disc_key'))->toBe(str_repeat('ab', 16))
        ->and(Schema::hasColumn('game_files', 'license_id'))->toBeFalse()
        ->and(Schema::hasColumn('game_files', 'disc_key'))->toBeFalse();
});

it('makes the table again when a run was cut short before the facts left game_files', function () {
    // As a stopped container leaves it: the table made, half filled, no row in migrations.
    Schema::create('game_file_meta', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('game_file_id')->unique();
        $table->string('license_id', 16)->nullable();
    });
    DB::table('game_file_meta')->insert(['game_file_id' => 1, 'license_id' => 'BLUS30538']);

    ($this->migration)()->up();

    expect(DB::table('game_file_meta')->count())->toBe(2)
        ->and(DB::table('game_file_meta')->where('game_file_id', 1)->value('disc_key'))->toBe(str_repeat('ab', 16))
        ->and(Schema::hasColumn('game_files', 'license_id'))->toBeFalse();
});

it('finishes a run cut short between dropping the index and the columns', function () {
    // The moment after the index went and before the columns did: the facts
    // are still on game_files, so the move is made again from them.
    Schema::table('game_files', function (Blueprint $table) {
        $table->dropIndex(['license_id']);
    });

    ($this->migration)()->up();

    expect(Schema::hasColumn('game_files', 'license_id'))->toBeFalse()
        ->and(DB::table('game_file_meta')->count())->toBe(2);
});

it('keeps the table when the move had finished and only the migrations row was lost', function () {
    ($this->migration)()->up();

    ($this->migration)()->up();

    expect(DB::table('game_file_meta')->where('game_file_id', 1)->value('disc_key'))->toBe(str_repeat('ab', 16));
});
