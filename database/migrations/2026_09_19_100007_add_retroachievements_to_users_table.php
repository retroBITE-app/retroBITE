<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('retroachievements_username')->nullable()->after('username');

            // How far the recent-unlocks puller has read. Without it the
            // minutes window in GetUserRecentAchievements is a guess, and
            // every hour the container is down is unlocks nobody fetches.
            $table->timestamp('retroachievements_synced_at')->nullable()->after('retroachievements_username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['retroachievements_username', 'retroachievements_synced_at']);
        });
    }
};
