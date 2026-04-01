<?php

declare(strict_types=1);

namespace App\Database;

use App\Models\User;
use Illuminate\Support\Arr;

class Seeder
{
    public static function seed(): void
    {
        if (User::count() > 0) {
            return;
        }

        $username = Arr::get($_ENV, 'AUTH_USER', 'retrobite');
        $password = Arr::get($_ENV, 'AUTH_PASS', 'retrobite');

        User::create([
            'username'   => $username,
            'password'   => password_hash($password, PASSWORD_BCRYPT),
            'created_at' => time(),
        ]);
    }
}
