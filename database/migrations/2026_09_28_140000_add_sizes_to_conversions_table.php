<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How big a finished conversion's source was and its output came out, so the
 * queue row can say what the conversion saved. Null until it is done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversions', function (Blueprint $table) {
            $table->unsignedBigInteger('source_bytes')->nullable()->after('eta_at');
            $table->unsignedBigInteger('output_bytes')->nullable()->after('source_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('conversions', function (Blueprint $table) {
            $table->dropColumn(['source_bytes', 'output_bytes']);
        });
    }
};
