<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // ScreenScraper's own `note`, which is out of 20, stored times
            // five. The scale is the one the interface shows and sorts in, and
            // half a point out of twenty is noise either way.
            //
            // Indexed because the library sorts on it, and nullable because
            // most of what the provider knows nobody has voted on.
            $table->unsignedTinyInteger('rating')->nullable()->after('region')->index();
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('rating');
        });
    }
};
