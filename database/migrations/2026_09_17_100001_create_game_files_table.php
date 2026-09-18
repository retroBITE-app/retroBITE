<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();

            // Relative to the library root, never absolute: the same library
            // mounted at a different path is the same library, and a row that
            // hard-codes /app/storage/app/games stops being true the moment
            // GAMES_PATH changes.
            //
            // 500 rather than the default 255 because nested redump sets get
            // long, and short of 768 because a utf8mb4 unique index cannot
            // exceed 3072 bytes.
            $table->string('path', 500)->unique();

            // Held separately so a lookup can send romnom without parsing the
            // path back apart on every call.
            $table->string('filename');
            $table->string('extension')->index();
            $table->unsignedBigInteger('size_bytes')->nullable();

            // Null means not computed yet, not "no checksum". Scanning does not
            // hash: reading several hundred gigabytes to fill a column nothing
            // has asked for yet would leave the library empty for hours.
            $table->string('crc', 8)->nullable();
            $table->string('md5', 32)->nullable()->index();
            $table->string('sha1', 40)->nullable();
            $table->timestamp('hashed_at')->nullable();

            $table->string('role')->index();
            $table->unsignedTinyInteger('disc_number')->nullable();
            $table->string('region')->nullable();

            // A track named by a cuesheet, or a disc named by a playlist. Set
            // during the scan's first pass, which is what keeps the second pass
            // from treating a .bin as a game in its own right.
            $table->foreignId('parent_id')->nullable()
                ->constrained('game_files')->nullOnDelete();

            // Soft-deletion by another name. A file that has gone missing is
            // usually an unmounted disk, not a deletion, and throwing the row
            // away would mean re-identifying the whole library when it returns.
            $table->timestamp('missing_since')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_files');
    }
};
