<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ra_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ra_game_id')->constrained('ra_games')->cascadeOnDelete();

            $table->unsignedInteger('unlocked_count')->default(0);
            $table->unsignedInteger('unlocked_hardcore_count')->default(0);

            // Denormalised here and not read from ra_games, so the library
            // list can show "31 / 49" with a single join and no aggregation
            // per row.
            $table->unsignedInteger('achievements_possible')->default(0);

            $table->unsignedInteger('points_earned')->default(0);
            $table->unsignedInteger('points_hardcore_earned')->default(0);
            $table->unsignedInteger('points_possible')->default(0);

            // beaten-softcore, beaten-hardcore, completed, mastered. Comes
            // from RetroAchievements and is never worked out locally.
            $table->string('highest_award_kind')->nullable();
            $table->timestamp('highest_award_at')->nullable();

            $table->unsignedInteger('site_rank')->nullable();
            $table->unsignedInteger('site_score')->nullable();

            $table->timestamp('last_unlock_at')->nullable();
            $table->timestamp('synced_at')->nullable();

            /* Set when a set changes, because points_possible is then wrong. */
            $table->boolean('stale')->default(false)->index();

            $table->timestamps();

            // Also the join index for the library list: user_id is constant
            // there and ra_game_id is the lookup, in that order.
            $table->unique(['user_id', 'ra_game_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ra_progress');
    }
};
