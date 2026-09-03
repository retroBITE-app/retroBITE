<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Game extends Model
{
    /** Path segment marking a file as a BIOS image rather than a game. */
    public const BIOS_SEGMENT = '/BIOS/';

    protected $table        = 'games';
    protected $primaryKey   = 'id';
    public    $keyType      = 'string';
    public    $incrementing = false;
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
        'first_seen_at',
        'last_seen_at',
    ];

    /**
     * Does this file sit in a BIOS folder rather than being a game?
     */
    public function isBios(): bool
    {
        return Str::contains((string) $this->file_path, self::BIOS_SEGMENT);
    }

    /**
     * Provider metadata, joined on the file's md5 rather than the game id so it
     * survives the file being deleted and re-added.
     */
    public function metadata(): HasOne
    {
        return $this->hasOne(GameMetadata::class, 'md5', 'file_md5');
    }
}
