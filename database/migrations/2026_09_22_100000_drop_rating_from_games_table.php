<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Take the provider's rating back out.
     *
     * Forward-only rather than an edit to the migration that added it: that
     * one has shipped, so an install in the wild already has the column and
     * would keep it for ever if the add were simply deleted. The index goes
     * with it — nothing sorts on a column that is not there.
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('rating');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating')->nullable()->after('region')->index();
        });
    }
};
