<?php

declare(strict_types=1);

namespace App\Models;

class Setting extends Model
{
    protected $table      = 'settings';
    protected $primaryKey = 'id';

    protected $fillable = [
        'group',
        'key',
        'value',
        'updated_at',
    ];
}
