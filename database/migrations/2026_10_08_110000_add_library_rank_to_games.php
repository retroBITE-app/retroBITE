<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The retroBite rank: where a game's score places it among every scored game
 * in this library, 1 the best (App\Services\LibraryRanking). Null for a game
 * with no score. Stored rather than counted on read, because a rank is the
 * whole library's business and a page shows one per game.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedInteger('library_rank')->nullable()->after('launchbox_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex(['library_rank']);
            $table->dropColumn('library_rank');
        });
    }
};
