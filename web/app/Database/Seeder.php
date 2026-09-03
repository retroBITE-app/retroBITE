<?php

declare(strict_types=1);

namespace App\Database;

use App\Models\User;
use App\Support\Env;

class Seeder
{
    /**
     * Create the initial web user on a fresh install. A no-op once one exists.
     */
    public static function seed(): void
    {
        if (User::count() > 0) {
            return;
        }

        $username = (string) Env::get('AUTH_USER', 'retrobite');
        $password = (string) Env::get('AUTH_PASS', 'retrobite');

        User::create([
            'username'   => $username,
            'password'   => password_hash($password, PASSWORD_BCRYPT),
            'created_at' => time(),
        ]);
    }
}
