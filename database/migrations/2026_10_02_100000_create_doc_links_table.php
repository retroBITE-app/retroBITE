<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which docs are about which games. A doc is a file on the docs disk, not a
 * row, so it is named by its path; that path is fixed at creation and never
 * follows a rename. A game going takes its links with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_links', function (Blueprint $table) {
            $table->id();
            $table->string('doc_path', 255);
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['doc_path', 'game_id']);
            $table->index('game_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_links');
    }
};
