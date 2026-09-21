<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ra_games', function (Blueprint $table) {
            // RetroAchievements' own game id as the primary key. Achievements
            // and progress hang off this rather than off games.id, because the
            // merge branch in GameMatcher deletes the losing Game row and a
            // person's unlocks must not go with it.
            $table->unsignedBigInteger('id')->primary();

            $table->unsignedInteger('ra_console_id')->index();
            $table->string('title');
            $table->string('image_icon')->nullable();
            $table->unsignedInteger('num_achievements')->default(0);
            $table->unsignedInteger('points_total')->default(0);

            // The denominator in "3.4% of players". Arrives in the same
            // GetGameExtended response as the achievements, so it costs
            // nothing, and the figure cannot be computed without it.
            $table->unsignedInteger('num_distinct_players')->default(0);
            $table->unsignedInteger('num_distinct_players_hardcore')->default(0);

            $table->timestamp('set_synced_at')->nullable();

            /* RetroAchievements' own DateModified for the set. */
            $table->timestamp('set_updated_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ra_games');
    }
};
