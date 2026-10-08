<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a console's toolbox reads off a disc, moved off game_files into a
 * table of its own (App\Models\GameFileMeta): the serial (license_id), the
 * video mode, and for a PS3 image whether it is still encrypted and its disc
 * key. game_files keeps what the scanner and the hashers own.
 *
 * The values already read are carried across. encrypted and disc_key were
 * only ever on development databases, so they are copied and dropped only
 * where they are there.
 */
return new class extends Migration
{
    /**
     * The facts that may still sit on game_files, by whether this database has them.
     *
     * @return list<string>
     */
    private function columns(): array
    {
        return array_values(array_filter(['license_id', 'video_mode', 'encrypted', 'disc_key'], function (string $column): bool {
            return Schema::hasColumn('game_files', $column);
        }));
    }

    public function up(): void
    {
        Schema::create('game_file_meta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_file_id')->unique()->constrained()->cascadeOnDelete();

            // The serial the disc names itself by, e.g. SLES_503.86 or BLUS30538.
            $table->string('license_id', 16)->nullable()->index();
            // PAL or NTSC, as a PS2 disc declares it.
            $table->string('video_mode', 4)->nullable();
            // A PS3 Redump image: still encrypted, and its disc key once one has fitted.
            $table->boolean('encrypted')->nullable();
            $table->string('disc_key', 32)->nullable();
            $table->timestamps();
        });

        $columns = $this->columns();

        DB::table('game_file_meta')->insertUsing(
            ['game_file_id', ...$columns, 'created_at', 'updated_at'],
            DB::table('game_files')
                ->select(['id', ...$columns])
                ->selectRaw('?, ?', [now(), now()])
                ->where(function (Builder $query) use ($columns): void {
                    foreach ($columns as $column) {
                        $query->orWhereNotNull($column);
                    }
                }),
        );

        Schema::table('game_files', function (Blueprint $table) use ($columns) {
            $table->dropIndex(['license_id']);
            $table->dropColumn($columns);
        });
    }

    public function down(): void
    {
        Schema::table('game_files', function (Blueprint $table) {
            $table->string('license_id', 16)->nullable()->index();
            $table->string('video_mode', 4)->nullable();
        });

        foreach (DB::table('game_file_meta')->get(['game_file_id', 'license_id', 'video_mode']) as $meta) {
            DB::table('game_files')->where('id', $meta->game_file_id)->update([
                'license_id' => $meta->license_id,
                'video_mode' => $meta->video_mode,
            ]);
        }

        Schema::dropIfExists('game_file_meta');
    }
};
