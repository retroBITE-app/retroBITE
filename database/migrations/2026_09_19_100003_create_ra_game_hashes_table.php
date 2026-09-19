<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ra_game_hashes', function (Blueprint $table) {
            $table->id();

            // Same type and collation as game_files.ra_hash on purpose.
            $table->string('hash', 32);

            $table->foreignId('ra_game_id')->constrained('ra_games')->cascadeOnDelete();
            $table->unsignedInteger('ra_console_id')->index();
            $table->timestamps();

            // hash is leftmost, so this serves the lookup as well as the
            // uniqueness. A separate index on hash alone would be dead weight.
            $table->unique(['hash', 'ra_game_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ra_game_hashes');
    }
};
