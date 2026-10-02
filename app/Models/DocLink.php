<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One doc said to be about one game. Read and written through
 * App\Services\DocLinks, which keeps the rows in step with the files.
 *
 * @property int $id
 * @property string $doc_path
 * @property int $game_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['doc_path', 'game_id'])]
class DocLink extends Model {}
