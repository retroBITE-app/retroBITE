<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ra_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ra_achievement_id')->constrained('ra_achievements')->cascadeOnDelete();

            // Denormalised from ra_achievements so the recompute can group by
            // game without a join. Safe because an achievement never moves
            // between games — and taken from our own row, never from the API
            // payload, so the grouping cannot disagree with the foreign key.
            $table->unsignedBigInteger('ra_game_id')->index();

            // Two dates on one row rather than a hardcore flag. A flag would
            // let the same achievement exist twice for one person, saying
            // contradictory things.
            $table->timestamp('unlocked_at')->nullable();
            $table->timestamp('unlocked_hardcore_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'ra_achievement_id']);
            $table->index(['user_id', 'ra_game_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ra_unlocks');
    }
};
