<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smaller copies of a cover, for the interface to show instead of the original.
 *
 * A downloaded cover can be several megabytes and two thousand pixels wide,
 * and the shelf draws it at most 280 CSS pixels high — the list view at 48.
 * MakeThumbnails writes one copy per size to the media disk beside the rest of
 * the artwork; these columns say where. Null until it has, and the interface
 * falls back to the original meanwhile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('thumbnail_list_path', 500)->nullable()->after('path');
            $table->string('thumbnail_grid_path', 500)->nullable()->after('thumbnail_list_path');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn(['thumbnail_list_path', 'thumbnail_grid_path']);
        });
    }
};
