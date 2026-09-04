<?php

declare(strict_types=1);

namespace App\Models;

class GameMetadata extends Model
{
    protected $table        = 'game_metadata';
    protected $primaryKey   = 'md5';
    public    $keyType      = 'string';
    public    $incrementing = false;

    protected $fillable = [
        'md5',
        'provider',
        'provider_id',
        'title',
        'description',
        'cover_url',
        'logo_url',
        'backdrop_url',
        'release_date',
        'genre',
        'players',
        'publisher',
        'developer',
        'raw',
        'fetched_at',
    ];

    protected $casts = [
        'raw' => 'array',
    ];
}
