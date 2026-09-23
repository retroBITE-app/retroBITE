<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The artwork the provider offers for a game, kept from the answer it came in.
 *
 * Every jeuInfos answer carries the whole list, and until now it was used once
 * and thrown away — so "fetch missing artwork" paid a second lookup per game
 * to be told the same thing. Keeping it makes identification, the rating and
 * the list one request.
 *
 * A table of its own rather than a JSON column on games: a well-covered game
 * lists every region's box, screenshot and logo, and the library reads games
 * with select * on every page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->unique()->constrained()->cascadeOnDelete();

            // As ScreenScraperService::sanitizeMedias() leaves it: the game's
            // own entries only, with the pre-signed credentials stripped.
            $table->json('medias');

            // When the provider said so. A list goes stale as people upload
            // artwork, which is what "re-fetch all" is for.
            $table->timestamp('fetched_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_lists');
    }
};
