<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // Indexed, not unique. screenscraper_id is unique and that is what
            // forces the merge branch in GameMatcher; a second merge mechanic
            // with different rules would be a separate decision, not a side
            // effect of adding a column.
            $table->unsignedBigInteger('retroachievements_id')->nullable()->after('screenscraper_id')->index();

            $table->string('retroachievements_status')->default('pending')->after('status')->index();
            $table->timestamp('retroachievements_matched_at')->nullable()->after('matched_at');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['retroachievements_id', 'retroachievements_status', 'retroachievements_matched_at']);
        });
    }
};
