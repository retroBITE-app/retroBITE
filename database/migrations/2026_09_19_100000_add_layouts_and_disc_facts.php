<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('console_source_folders', function (Blueprint $table) {
            // How this console's folder is arranged, as the person who added it
            // said. Null means nobody was asked — either the console declares
            // one layout and there was nothing to ask, or the row predates this
            // column — and the console's own default stands in.
            $table->string('layout')->nullable();
        });

        Schema::table('game_files', function (Blueprint $table) {
            // Read out of the file itself rather than from the provider: a PS2
            // disc carries its serial in SYSTEM.CNF and ScreenScraper does not
            // return one. A fact about this file, like its checksums.
            $table->string('license_id', 16)->nullable()->index();
            $table->string('video_mode', 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('game_files', function (Blueprint $table) {
            $table->dropColumn(['license_id', 'video_mode']);
        });

        Schema::table('console_source_folders', function (Blueprint $table) {
            $table->dropColumn('layout');
        });
    }
};
