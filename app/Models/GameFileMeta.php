<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a console's toolbox has read off one file's disc: its serial, video
 * mode, and for a PS3 image whether it is still encrypted and its disc key.
 *
 * Kept apart from GameFile, which holds what the scanner and the hashers own.
 * A file nothing has read yet has no row.
 *
 * @property int $id
 * @property int $game_file_id
 * @property string|null $license_id the serial the disc names itself by, e.g. SLES_503.86
 * @property string|null $video_mode PAL or NTSC, as the disc declares it
 * @property bool|null $encrypted whether the image is still encrypted (a PS3 Redump dump); null until read
 * @property string|null $disc_key a PS3 disc's key once one has been seen to fit it, 32 upper-case hex digits; kept after decrypting
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read GameFile $file
 */
#[Fillable(['game_file_id', 'license_id', 'video_mode', 'encrypted', 'disc_key'])]
class GameFileMeta extends Model
{
    protected $table = 'game_file_meta';

    protected function casts(): array
    {
        return [
            'encrypted' => 'boolean',
        ];
    }

    /** @return BelongsTo<GameFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(GameFile::class, 'game_file_id');
    }
}
