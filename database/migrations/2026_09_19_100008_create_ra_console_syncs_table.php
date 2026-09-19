<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ra_console_syncs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('ra_console_id')->unique();
            $table->timestamp('synced_at')->nullable();
            $table->unsignedInteger('games')->default(0);
            $table->unsignedInteger('hashes')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ra_console_syncs');
    }
};
