<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the running phase of a conversion is expected to end, for the ETA over
 * its progress bar. A moment rather than a number of seconds, so the page
 * counts it down between the runner's reports instead of freezing on the last.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversions', function (Blueprint $table) {
            $table->timestamp('eta_at')->nullable()->after('progress');
        });
    }

    public function down(): void
    {
        Schema::table('conversions', function (Blueprint $table) {
            $table->dropColumn('eta_at');
        });
    }
};
