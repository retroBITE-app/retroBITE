<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_collections', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // game_collection_game reads oddly but is the Laravel convention for a
        // pivot between game_collections and games, so the relation needs no
        // configuration to find it.
        //
        // Deliberately not a morph pivot. Exactly one thing goes in a
        // collection today, and a polymorphic table would cost a type column,
        // a wider index and clumsier queries to buy flexibility nothing has
        // asked for. Converting later is one migration.
        Schema::create('game_collection_game', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            // A hand-curated list has an order the curator chose.
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['game_collection_id', 'game_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_collection_game');
        Schema::dropIfExists('game_collections');
    }
};
