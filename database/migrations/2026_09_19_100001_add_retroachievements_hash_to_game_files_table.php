<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_files', function (Blueprint $table) {
            // string(32) rather than char(32), to match md5 above it — and
            // more importantly to match ra_game_hashes.hash exactly. A type or
            // collation mismatch across that join disables the index silently
            // and turns every rematch into a full scan.
            $table->string('ra_hash', 32)->nullable()->after('sha1')->index();

            // The size and mtime the file had *when it was hashed*, which is
            // why they are not size_bytes: the scanner rewrites that on every
            // pass, and comparing a value against itself proves nothing.
            $table->unsignedBigInteger('ra_hash_size')->nullable()->after('ra_hash');
            $table->unsignedBigInteger('ra_hash_mtime')->nullable()->after('ra_hash_size');

            $table->timestamp('ra_hashed_at')->nullable()->after('ra_hash_mtime');
        });
    }

    public function down(): void
    {
        Schema::table('game_files', function (Blueprint $table) {
            $table->dropColumn(['ra_hash', 'ra_hash_size', 'ra_hash_mtime', 'ra_hashed_at']);
        });
    }
};
