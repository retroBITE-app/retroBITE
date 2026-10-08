<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A PS3 disc's key, once one has been seen to fit it, as 32 hex digits. The
 * .dkey beside the image is what ps3netsrv reads, but decrypting deletes it;
 * this keeps the key on record afterwards, to show and hand on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_files', function (Blueprint $table) {
            $table->string('disc_key', 32)->nullable()->after('encrypted');
        });
    }

    public function down(): void
    {
        Schema::table('game_files', function (Blueprint $table) {
            $table->dropColumn('disc_key');
        });
    }
};
