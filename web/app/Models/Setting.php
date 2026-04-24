<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table      = 'settings';
    protected $primaryKey = 'id';
    public    $timestamps = false;

    protected $fillable = [
        'group',
        'key',
        'value',
        'updated_at',
    ];
}
