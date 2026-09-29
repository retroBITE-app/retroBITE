<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * What one kind of conversion has made of the sources put through it on one
 * console, summed: the ratio the Conversion page's size estimate goes by.
 *
 * Sums rather than an average of ratios, so a 4 GB disc weighs as much as it
 * should against a 40 MB one.
 *
 * @property int $id
 * @property string $converter
 * @property string $console
 * @property int $conversions
 * @property int $source_bytes
 * @property int $output_bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['converter', 'console', 'conversions', 'source_bytes', 'output_bytes'])]
class ConversionStat extends Model
{
    protected function casts(): array
    {
        return [
            'conversions' => 'integer',
            'source_bytes' => 'integer',
            'output_bytes' => 'integer',
        ];
    }

    /**
     * Count one finished conversion in. In the database, not read-add-write,
     * so two finishing at once both count: the row is made or found against
     * the unique (converter, console) index, then added to in one statement.
     */
    public static function record(string $converter, string $console, int $sourceBytes, int $outputBytes): void
    {
        if ($sourceBytes <= 0 || $outputBytes <= 0) {
            return;
        }

        $stat = self::query()->createOrFirst(['converter' => $converter, 'console' => $console]);

        self::query()->whereKey($stat->id)->incrementEach([
            'conversions' => 1,
            'source_bytes' => $sourceBytes,
            'output_bytes' => $outputBytes,
        ]);
    }

    /**
     * Output bytes per source byte for every conversion a console has done,
     * by converter key, in one query; a converter that has done none is
     * absent, so the page shows no estimate rather than a made-up one.
     *
     * @return array<string, float>
     */
    public static function ratios(string $console): array
    {
        return self::query()
            ->where('console', $console)
            ->where('source_bytes', '>', 0)
            ->where('output_bytes', '>', 0)
            ->get()
            ->mapWithKeys(function (self $stat): array {
                return [$stat->converter => $stat->output_bytes / $stat->source_bytes];
            })
            ->all();
    }

    /** One converter's ratio on one console, or null until it has done one. */
    public static function ratio(string $converter, string $console): ?float
    {
        $ratio = Arr::get(self::ratios($console), $converter);

        return is_float($ratio) ? $ratio : null;
    }
}
