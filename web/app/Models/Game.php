<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Game extends Model
{
    protected $table        = 'games';
    protected $primaryKey   = 'id';
    public    $keyType      = 'string';
    public    $incrementing = false;
    public    $timestamps   = false;
    protected $hidden       = ['file_path'];

    protected $fillable = [
        'id',
        'console',
        'file_name',
        'file_path',
        'file_size',
        'file_md5',
        'title',
        'region',
        'cover_url',
        'first_seen_at',
        'last_seen_at',
    ];

    public function isBios(): bool
    {
        return Str::contains((string) $this->file_path, '/BIOS/');
    }

    public function metadata(): HasOne
    {
        return $this->hasOne(GameMetadata::class, 'md5', 'file_md5');
    }
}
