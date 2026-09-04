<?php

declare(strict_types=1);

namespace App\Database;

use App\Repositories\UserRepository;
use App\Support\Env;

class Seeder
{
    /**
     * Create the initial web user on a fresh install. A no-op once one exists.
     */
    public static function seed(): void
    {
        $users = new UserRepository();

        if ($users->count() > 0) {
            return;
        }

        $users->create(
            (string) Env::get('AUTH_USER', 'retrobite'),
            (string) Env::get('AUTH_PASS', 'retrobite'),
        );
    }
}
