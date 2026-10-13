<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A file row outlives its file: what is removed is archived, not deleted
 * (docs/adr/0009).
 *
 * The row is the only record of which dump a file was — the checksums and the
 * name the provider knows it by — and a converted or decrypted copy is a dump
 * nobody has catalogued. So a removed file keeps its row under deleted_at,
 * with why, and a file made out of another points at it (source_id).
 *
 * A path is unique among the files that are there, not among every file
 * there ever was: a file can come back where an archived one was. MariaDB
 * has no partial index, so the unique index is on a column that holds the
 * path only while the row is live, NULL being no duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_files', function (Blueprint $table) {
            $table->softDeletes();
            $table->string('archived_reason', 16)->nullable()->after('deleted_at');
            $table->foreignId('source_id')->nullable()->after('parent_id')
                ->constrained('game_files')->nullOnDelete();
            $table->string('live_path', 500)->nullable()
                ->storedAs('IF(deleted_at IS NULL, path, NULL)');
            $table->index(['game_id', 'deleted_at']);
        });

        Schema::table('game_files', function (Blueprint $table) {
            $table->dropUnique(['path']);
            $table->index('path');
            $table->unique('live_path');
        });
    }

    public function down(): void
    {
        Schema::table('game_files', function (Blueprint $table) {
            $table->dropUnique(['live_path']);
            $table->dropIndex(['path']);
            $table->dropIndex(['game_id', 'deleted_at']);
            $table->dropConstrainedForeignId('source_id');
            $table->dropColumn(['live_path', 'archived_reason', 'deleted_at']);
        });

        Schema::table('game_files', function (Blueprint $table) {
            $table->unique('path');
        });
    }
};
