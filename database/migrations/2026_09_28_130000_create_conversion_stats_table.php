<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How big each kind of conversion's output has come out, per console, for
 * the size estimate beside each format on the Conversion page.
 *
 * Learned rather than guessed: a PS2 DVD image hardly compresses and a PS1
 * disc with audio tracks compresses well, so one fixed ratio per format was
 * wrong for most games. A table of its own rather than an average over the
 * conversions, because clearing finished conversions off the queue must not
 * throw away what they taught.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversion_stats', function (Blueprint $table) {
            $table->id();
            $table->string('converter', 32);
            $table->string('console', 32);
            $table->unsignedInteger('conversions')->default(0);
            $table->unsignedBigInteger('source_bytes')->default(0);
            $table->unsignedBigInteger('output_bytes')->default(0);
            $table->timestamps();

            $table->unique(['converter', 'console']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_stats');
    }
};
