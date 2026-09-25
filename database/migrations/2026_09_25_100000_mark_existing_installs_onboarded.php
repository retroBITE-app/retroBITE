<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Onboarding arrived after installs were already running, and an
        // install with an account has been set up by whoever made it — sending
        // it through a first-run wizard on the next deploy would be a nuisance.
        // A fresh install reaches this with no users (migrate runs before
        // anybody can sign up), so it is left unmarked and gets the wizard.
        //
        // The key is spelled out rather than read off AppSetting::IS_ONBOARDED:
        // a migration that imports a model breaks the day the model changes.
        if (DB::table('users')->doesntExist()) {
            return;
        }

        $now = now();

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'is_onboarded'],
            ['value' => json_encode(true), 'created_at' => $now, 'updated_at' => $now],
        );
    }

    public function down(): void
    {
        // Left in place: a rolled-back deploy has no onboarding to send anybody
        // through, and nothing else reads the key.
    }
};
