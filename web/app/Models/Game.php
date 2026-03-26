<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Game extends Model
{
    protected $table            = 'games';
    protected $primaryKey       = 'id';
    public    $keyType          = 'string';
    public    $incrementing     = false;
    public    $timestamps       = false;
    protected $hidden           = ['file_path'];

    protected $fillable = [
        'id',
        'console',
        'file_name',
        'file_path',
        'file_size',
        'title',
        'region',
        'cover_url',
        'first_seen_at',
        'last_seen_at',
    ];
}