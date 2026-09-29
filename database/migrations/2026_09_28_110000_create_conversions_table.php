<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The conversion queue: one row per conversion somebody asked for.
 *
 * A row rather than only a job, because the job table forgets a job the moment
 * it finishes and knows nothing of how far one has got. This keeps what the
 * page shows — state, progress, the tool's own log, why it failed — past the
 * job, past the tab that asked for it, and past a restart, after which a row
 * left running is marked as interrupted rather than left to spin.
 *
 * Paths are relative to the library root, as game_files.path is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversions', function (Blueprint $table) {
            $table->id();
            $table->string('console', 32);
            // A key in config/converters.php.
            $table->string('converter', 32);
            $table->foreignId('game_id')->nullable()->constrained()->nullOnDelete();
            // The row that was picked: a playlist, a cue sheet or an image.
            $table->foreignId('game_file_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');
            // Inside the console's folder, '' for the folder itself.
            $table->string('directory', 500)->default('');
            $table->json('sources');
            $table->json('outputs')->nullable();
            $table->json('options');
            // queued | running | verifying | done | failed | cancelled
            $table->string('status', 16)->default('queued')->index();
            $table->decimal('progress', 5, 2)->default(0);
            $table->string('failure', 32)->nullable();
            $table->mediumText('log')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversions');
    }
};
