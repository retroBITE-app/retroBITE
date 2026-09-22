<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Put `rating` back on a database that ran the drop.
 *
 * The column is added by 2026_09_21_100001 and was taken away again by a drop
 * migration that has since been deleted from the tree. A database migrated
 * while that file existed is missing the column and needs this; a database
 * migrated from scratch today runs the original add and already has it, which
 * is why both ends are guarded. Neither migration can be removed without
 * breaking one of the two.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('games', 'rating')) {
            return;
        }

        Schema::table('games', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating')->nullable()->after('region')->index();
        });
    }

    public function down(): void
    {
        // Nothing. The column belongs to 2026_09_21_100001, which drops it.
    }
};
