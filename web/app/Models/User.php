<?php

declare(strict_types=1);

namespace App\Models;

class User extends Model
{
    protected $table      = 'users';
    protected $hidden     = ['password'];

    protected $fillable = [
        'username',
        'password',
        'created_at',
    ];
}
