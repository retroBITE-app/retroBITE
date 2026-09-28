<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A console sent at once is one transfer per game, tied together by the id
 * of the send, so the console's page can follow the whole of it and its game
 * list is written once, after the last game. The send's own id rather than
 * the job batch's: the batch is made only once the share has been asked what
 * it already holds, and some sends need no batch at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->string('batch_id', 36)->nullable()->after('target')->index();
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn('batch_id');
        });
    }
};
