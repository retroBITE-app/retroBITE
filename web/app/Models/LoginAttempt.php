<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Failed-login counter for one client address.
 */
class LoginAttempt extends Model
{
    protected $table        = 'login_attempts';
    protected $primaryKey   = 'ip';
    protected $keyType      = 'string';
    public    $incrementing = false;
    public    $timestamps   = false;

    protected $fillable = [
        'ip',
        'attempts',
        'window_start',
        'locked_until',
    ];

    protected $casts = [
        'attempts'     => 'int',
        'window_start' => 'int',
        'locked_until' => 'int',
    ];
}
