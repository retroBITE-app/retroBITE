<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ra_achievements', function (Blueprint $table) {
            /* RetroAchievements' own achievement id. */
            $table->unsignedBigInteger('id')->primary();

            $table->foreignId('ra_game_id')->constrained('ra_games')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('points')->default(0);
            $table->unsignedInteger('true_ratio')->default(0);

            // The badge's name, never a built URL: swapping the CDN for local
            // storage later must not mean re-syncing every set.
            $table->string('badge_name')->nullable();

            /* progression, win_condition, missable, or absent. */
            $table->string('kind')->nullable();

            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('core')->default(true);
            $table->unsignedInteger('num_awarded')->default(0);
            $table->unsignedInteger('num_awarded_hardcore')->default(0);

            // Gone from the set, not gone from history. Someone may have
            // unlocked it, and deleting the row would delete that too.
            $table->timestamp('removed_at')->nullable();

            $table->timestamps();

            $table->index(['ra_game_id', 'display_order']);

            // Drives the counter recompute. Indexing core or removed_at alone
            // would be useless — both are two-valued and the optimizer skips
            // them — but together behind ra_game_id they are the whole query.
            $table->index(['ra_game_id', 'core', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ra_achievements');
    }
};
