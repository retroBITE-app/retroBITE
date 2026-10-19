<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a file holds (App\Enums\FileContent): game, DLC or update. Beside role,
 * which says how the file is built rather than what is in it — an add-on disc
 * can be a rom, or a cuesheet and its tracks. Null until the dump's name or a
 * toolbox reading the disc says; existing files fill on the next scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_files', function (Blueprint $table) {
            $table->string('content_type', 16)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('game_files', function (Blueprint $table) {
            $table->dropColumn('content_type');
        });
    }
};
