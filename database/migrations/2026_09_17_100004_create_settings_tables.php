<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which of the provider's media types to fetch automatically. One row
        // per raw type, so enabling video is a checkbox rather than a release.
        Schema::create('media_type_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('media_type')->unique();
            $table->boolean('enabled')->default(false)->index();
            $table->timestamps();
        });

        // Settings that are genuinely user-changeable at runtime, which is why
        // they are not in config/: a config file is redeployed, not toggled.
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        // Only written when a console's ROMs are not where convention says.
        // An absent row is the normal case and means games_path/{folder}.
        Schema::create('console_source_folders', function (Blueprint $table) {
            $table->id();
            $table->string('console')->unique();
            // Relative to the library root, like files.path — the picker only
            // offers directories beneath the one mounted into the container.
            $table->string('path', 500);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('console_source_folders');
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('media_type_preferences');
    }
};
