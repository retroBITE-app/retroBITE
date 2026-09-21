<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // Which region's artwork this game shows, overriding the library
            // setting. Distinct from `region`, which is the ROM's own: a
            // Japanese import can be the copy somebody owns while its English
            // cover is the one they want to look at.
            //
            // Null is the ordinary state and means "whatever Settings says",
            // so changing the library preference still moves every game that
            // has not been spoken for.
            $table->string('media_region')->nullable()->after('region');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('media_region');
        });
    }
};
