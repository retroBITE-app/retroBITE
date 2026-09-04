<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Base for retroBITE's models. The schema keeps its own integer timestamps, so
 * Eloquent's created_at/updated_at handling is off everywhere.
 */
abstract class Model extends EloquentModel
{
    public $timestamps = false;
}
