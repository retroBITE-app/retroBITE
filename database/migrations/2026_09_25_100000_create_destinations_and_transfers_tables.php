<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Network shares games can be sent to, and each sending.
 *
 * A destination is a share on another machine — a Batocera box's `share`
 * above all — saved once so it is picked from a list afterwards. The password
 * is encrypted by the model's cast; the key is APP_KEY, like every other
 * secret here.
 *
 * A transfer is one game sent to one destination for one target, kept so the
 * game page can say how it went after the tab that started it has closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // A name or an address; whatever answered when it was found.
            $table->string('host');
            $table->string('share');
            // Below the share's root, '' for the root itself.
            $table->string('folder')->default('');
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->timestamps();
        });

        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('destination_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target');
            // queued | running | done | failed
            $table->string('status')->default('queued');
            $table->unsignedInteger('files_total')->default(0);
            $table->unsignedInteger('files_done')->default(0);
            $table->unsignedInteger('files_skipped')->default(0);
            $table->unsignedBigInteger('bytes_total')->default(0);
            // A TransferFailure value when it failed.
            $table->string('failure')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['game_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
        Schema::dropIfExists('destinations');
    }
};
