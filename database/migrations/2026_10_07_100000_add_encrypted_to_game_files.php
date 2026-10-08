<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a disc image is still encrypted, as its console's toolbox read it
 * (App\Decryption\Ps3Disc for PS3 Redump dumps). Null until it has been read, and
 * for every console whose discs are never encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_files', function (Blueprint $table) {
            $table->boolean('encrypted')->nullable()->after('video_mode');
        });
    }

    public function down(): void
    {
        Schema::table('game_files', function (Blueprint $table) {
            $table->dropColumn('encrypted');
        });
    }
};
