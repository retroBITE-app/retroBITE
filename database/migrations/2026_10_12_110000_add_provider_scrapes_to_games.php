<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many have scraped the game's most popular dump, of every dump the
 * provider knows: a popularity source for the retroBite score.
 *
 * Every identified game is marked as not yet asked, so `retrobite:dumps
 * --queue` fetches the figure for games recorded before it was kept. A game
 * matched from here on carries it out of the match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedInteger('provider_scrapes')->nullable()->after('library_rank');
        });

        DB::table('games')->whereNotNull('screenscraper_id')->update(['dumps_recorded_at' => null]);
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('provider_scrapes');
        });
    }
};
