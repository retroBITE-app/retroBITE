<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A LaunchBox name with its subtitle left off ("Ehrgeiz" for "Ehrgeiz: God
 * Bless the Ring"), for a library that calls the game by its main title.
 * Looked at only after every whole name and alias, and kept only where one
 * game on the platform has it. The next index sync writes them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('launchbox_names', function (Blueprint $table) {
            $table->boolean('shortened')->default(false)->after('alias');
        });
    }

    public function down(): void
    {
        Schema::table('launchbox_names', function (Blueprint $table) {
            $table->dropColumn('shortened');
        });
    }
};
