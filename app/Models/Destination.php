<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A network share games can be sent to: a folder on another machine, reached
 * over SMB. Written by FileTransferJob, never read back into the library.
 *
 * @property int $id
 * @property string $name
 * @property string $host
 * @property string $share
 * @property string $folder '' for the share's root
 * @property string|null $username null for a guest share
 * @property string|null $password encrypted at rest
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'host', 'share', 'folder', 'username', 'password'])]
#[Hidden(['password'])]
class Destination extends Model
{
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
        ];
    }

    /** @return HasMany<Transfer, $this> */
    public function transfers(): HasMany
    {
        return $this->hasMany(Transfer::class);
    }

    /** How the share is written in Windows, which is how most people know it. */
    public function address(): string
    {
        return '\\\\'.$this->host.'\\'.$this->share.($this->folder !== '' ? '\\'.str_replace('/', '\\', $this->folder) : '');
    }
}
