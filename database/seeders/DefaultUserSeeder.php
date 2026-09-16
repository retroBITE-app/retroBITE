<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Guarantees a known login exists on a fresh install.
 *
 * Runs from the container entrypoint on every boot, but only acts when the
 * users table is empty — so the account comes back if you have locked yourself
 * out, and stays gone once you have created an admin of your own and deleted
 * it.
 */
class DefaultUserSeeder extends Seeder
{
    public const USERNAME = 'retrobite';

    public const EMAIL = 'retrobite@retrobite.local';

    public const PASSWORD = 'retrobite';

    public function run(): void
    {
        if (User::query()->exists()) {
            return;
        }

        User::create([
            'name' => 'retrobite',
            'username' => self::USERNAME,
            'email' => self::EMAIL,
            // Cast to 'hashed' on the model, so this is hashed on write.
            'password' => self::PASSWORD,
        ])->forceFill([
            // Fortify blocks unverified accounts from most routes, and there is
            // no mailer configured to verify through.
            'email_verified_at' => now(),
        ])->save();

        $this->command->warn('Seeded default user '.self::USERNAME.' / '.self::PASSWORD.' — change this password.');
    }
}
