<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A local copy of the LaunchBox Games Database's ratings, and the retroBite
 * score that replaces ScreenScraper's note in games.rating (docs/adr/0006).
 *
 * launchbox_games holds one row per game on a platform some console maps to,
 * with the players' average and how many voted. launchbox_names is what a
 * game is looked up by: its name and every alternate name, each reduced to a
 * LaunchBoxTitle key. Neither has a foreign key, because LaunchBoxIndex
 * rebuilds both a platform at a time and games.launchbox_id must survive
 * that. launchbox_platforms carries each platform's average, which the score
 * pulls a thinly voted game towards, and when it was last read.
 *
 * The ScreenScraper notes already in games.rating are cleared: they are not
 * the score, and leaving them would show the old number beside the new one
 * until every game had been scored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('launchbox_games', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('platform', 100)->index();
            $table->string('name');
            $table->double('rating')->nullable();
            $table->unsignedInteger('votes')->default(0);
        });

        Schema::create('launchbox_names', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('launchbox_game_id')->index();
            $table->string('platform', 100);
            $table->string('name_key');
            $table->boolean('alias')->default(false);

            $table->index(['platform', 'name_key']);
        });

        Schema::create('launchbox_platforms', function (Blueprint $table) {
            $table->string('name', 100)->primary();
            $table->double('mean_rating')->nullable();
            $table->unsignedInteger('games')->default(0);
            $table->unsignedInteger('rated')->default(0);
            $table->timestamp('synced_at')->nullable();
        });

        Schema::table('games', function (Blueprint $table) {
            $table->unsignedInteger('launchbox_id')->nullable()->after('rating')->index();
        });

        DB::table('games')->whereNotNull('rating')->update(['rating' => null]);
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex(['launchbox_id']);
            $table->dropColumn('launchbox_id');
        });

        Schema::dropIfExists('launchbox_platforms');
        Schema::dropIfExists('launchbox_names');
        Schema::dropIfExists('launchbox_games');
    }
};
