<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the provider says about each dump a file is (App\Support\Matching\ProviderDumps):
 * how many times it has been scraped — how many people hold that very dump —
 * and its flags, beta to translation. A transfer sends the version most
 * people play, and never a hack or a translation the name did not admit to.
 *
 * games.dumps_recorded_at says the game's files have been asked about, so the
 * backfill (retrobite:dumps) can tell them from the ones that have not —
 * whether or not the provider knew any of its dumps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_files', function (Blueprint $table) {
            $table->unsignedInteger('scrapes')->nullable()->after('region');
            $table->json('provider_flags')->nullable()->after('scrapes');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->timestamp('dumps_recorded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('game_files', function (Blueprint $table) {
            $table->dropColumn(['scrapes', 'provider_flags']);
        });

        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('dumps_recorded_at');
        });
    }
};
