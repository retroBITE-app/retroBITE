<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\User;

class UserRepository
{
    /**
     * The user with this username, or null.
     */
    public function findByUsername(string $username): ?User
    {
        return User::where('username', $username)->first();
    }

    /**
     * How many users exist. Used to decide whether to seed.
     */
    public function count(): int
    {
        return User::count();
    }

    /**
     * Create a user, hashing the plaintext password.
     */
    public function create(string $username, string $plainPassword): User
    {
        return User::create([
            'username'   => $username,
            'password'   => password_hash($plainPassword, PASSWORD_BCRYPT),
            'created_at' => time(),
        ]);
    }
}
