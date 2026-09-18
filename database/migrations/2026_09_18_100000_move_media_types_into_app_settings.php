<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // media_type_preferences existed because the settings screen needed a
        // row to draw a checkbox from. The catalogue now lives in
        // config/media_types.php, so all the database has to remember is which
        // of them are switched on — one json list, beside every other runtime
        // setting.
        //
        // The key is spelled out rather than read off AppSetting::MEDIA_TYPES:
        // a migration that imports a model breaks the day the model changes.
        if (! Schema::hasTable('media_type_preferences')) {
            return;
        }

        // Only carried when there is something to carry. An install whose table
        // is empty never ran the seeder, and leaving the key absent hands it the
        // shipped selection rather than nothing at all.
        if (DB::table('media_type_preferences')->exists()) {
            $enabled = DB::table('media_type_preferences')
                ->where('enabled', true)
                ->orderBy('media_type')
                ->pluck('media_type')
                ->all();

            // An empty result is a real answer: somebody who switched every
            // type off must not be handed the defaults back.
            $now = now();

            DB::table('app_settings')->updateOrInsert(
                ['key' => 'media_types'],
                ['value' => json_encode(array_values($enabled)), 'created_at' => $now, 'updated_at' => $now],
            );
        }

        Schema::drop('media_type_preferences');
    }

    public function down(): void
    {
        // The table comes back empty and stays empty: the seeder that filled it
        // is gone, and the answer now lives in app_settings, which is left where
        // it is. Rolling this back is undoing a deploy, and nothing reads the
        // table any more.
        Schema::create('media_type_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('media_type')->unique();
            $table->boolean('enabled')->default(false)->index();
            $table->timestamps();
        });
    }
};
