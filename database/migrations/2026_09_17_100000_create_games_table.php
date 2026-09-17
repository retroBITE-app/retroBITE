<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();

            // ScreenScraper's game id, and the canonical identity of a game:
            // stable across every region, revision and disc of the same title.
            // Null until a lookup succeeds, so a placeholder can exist from the
            // moment a file is found. Unique when set, which is what collapses
            // four discs of one game into one row.
            $table->unsignedBigInteger('screenscraper_id')->nullable()->unique();

            // A key from config/consoles, not a foreign key: consoles are
            // editable config rather than rows.
            $table->string('console')->index();

            $table->string('title');
            $table->string('slug');
            $table->string('status')->index();

            $table->text('description')->nullable();
            // Kept as provider text. It arrives in a dozen shapes and parsing
            // it into a date would invent precision the source does not have.
            $table->string('release_date')->nullable();
            $table->string('genre')->nullable();
            $table->string('players')->nullable();
            $table->string('publisher')->nullable();
            $table->string('developer')->nullable();
            $table->string('region')->nullable();

            $table->timestamp('matched_at')->nullable();
            $table->timestamps();

            // Two consoles can each hold a game called "Aladdin"; one console
            // holding it twice is a duplicate worth rejecting.
            $table->unique(['console', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
