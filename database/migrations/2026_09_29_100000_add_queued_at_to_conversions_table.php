<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a conversion was last put in the queue — at first, and again on a
 * retry — for the sidebar's "done of total" over the batch in progress.
 *
 * created_at could not do it: a retried conversion keeps the day it was first
 * asked for, and the batch then reached back to take in everything since.
 * Indexed with the status, which is how the sidebar and the queue look rows up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversions', function (Blueprint $table) {
            $table->timestamp('queued_at')->nullable()->after('cancel_requested_at');
            $table->index(['status', 'queued_at']);
        });

        DB::table('conversions')->whereNull('queued_at')->update(['queued_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('conversions', function (Blueprint $table) {
            $table->dropIndex(['status', 'queued_at']);
            $table->dropColumn('queued_at');
        });
    }
};
