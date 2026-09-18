<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();

            // The provider's own type name — box-2D, ss, fanart, wheel-hd. Kept
            // raw rather than mapped onto our three display roles, so adding a
            // type later needs no migration and loses nothing on the way in.
            $table->string('screenscraper_type')->index();

            // Absent entirely on region-less media such as fanart and video.
            $table->string('region')->nullable();

            $table->string('md5', 32);
            $table->string('path', 500);
            $table->string('extension');
            $table->unsignedBigInteger('size_bytes')->nullable();

            // Stored with devid, devpassword, ssid and sspassword stripped out;
            // the provider hands them out pre-signed. Credentials are put back
            // at download time from config.
            $table->string('source_url', 1000)->nullable();

            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            // Content, not metadata, decides duplication: a game may have five
            // screenshots, but never the same image twice.
            $table->unique(['game_id', 'md5']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
