<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was chosen for a transfer beyond its target — the region to send
 * first, the artwork to send at all (App\Transfers\TransferOptions) — so the
 * jobs that plan a game again on the queue plan the same thing. Null for
 * every row before it, which is what nothing chosen means.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->json('options')->nullable()->after('target');
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn('options');
        });
    }
};
