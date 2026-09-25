<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many playable files a console's folder held when it was last counted.
 *
 * Counted by a background job (MeasureLibrary) and read by the pages, so no
 * page walks a folder: the count used to be taken inside a request whenever a
 * one-minute cache had run out. On the row the consoles page already reads, so
 * showing it costs no query at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('console_source_folders', function (Blueprint $table) {
            // Null until the first count, which is not the same as none found.
            $table->unsignedInteger('file_count')->nullable()->after('layout');
            $table->timestamp('counted_at')->nullable()->after('file_count');
        });
    }

    public function down(): void
    {
        Schema::table('console_source_folders', function (Blueprint $table) {
            $table->dropColumn(['file_count', 'counted_at']);
        });
    }
};
