<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A LaunchBox name with its subtitle left off ("Ehrgeiz" for "Ehrgeiz: God
 * Bless the Ring"), for a library that calls the game by its main title.
 * Looked at only after every whole name and alias, and kept only where one
 * game on the platform has it.
 *
 * The index is emptied as well: its keys were reduced the old way, and the
 * lookup now reduces titles the new way, so not one would meet until the
 * weekly sync. An empty index is synced on the hour by the scheduler, and
 * nothing is scored against it meanwhile (GameScorer), so the games keep the
 * scores they have until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('launchbox_names', function (Blueprint $table) {
            $table->boolean('shortened')->default(false)->after('alias');
        });

        DB::table('launchbox_names')->delete();
        DB::table('launchbox_games')->delete();
        DB::table('launchbox_platforms')->delete();
    }

    public function down(): void
    {
        Schema::table('launchbox_names', function (Blueprint $table) {
            $table->dropColumn('shortened');
        });
    }
};
