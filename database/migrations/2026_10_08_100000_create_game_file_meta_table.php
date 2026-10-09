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
 *
 * Safe to run again after a run cut short. MariaDB commits each DDL
 * statement on its own, so a container stopped mid-way leaves the new table
 * behind with no row in migrations, and every later boot's migrate would
 * fail on "already exists" — the web container with it. Until the last
 * statement the values are still on game_files, so a table found then is a
 * half-made copy and is made again; once they are gone from game_files the
 * move had finished, and the table is the only copy and is kept.
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
        $columns = $this->columns();

        if ($columns === [] && Schema::hasTable('game_file_meta')) {
            return;
        }

        Schema::dropIfExists('game_file_meta');

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

        // Its own statement, so it may have gone already in a run cut short.
        if (Schema::hasIndex('game_files', ['license_id'])) {
            Schema::table('game_files', function (Blueprint $table) {
                $table->dropIndex(['license_id']);
            });
        }

        Schema::table('game_files', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }

    /**
     * Every fact back onto game_files, disc keys included: once an image has
     * been decrypted its .dkey is gone, and the key on record is the only copy.
     */
    public function down(): void
    {
        Schema::table('game_files', function (Blueprint $table) {
            $table->string('license_id', 16)->nullable()->index();
            $table->string('video_mode', 4)->nullable();
            $table->boolean('encrypted')->nullable();
            $table->string('disc_key', 32)->nullable();
        });

        DB::table('game_files')
            ->join('game_file_meta', 'game_file_meta.game_file_id', '=', 'game_files.id')
            ->update([
                'game_files.license_id' => DB::raw('game_file_meta.license_id'),
                'game_files.video_mode' => DB::raw('game_file_meta.video_mode'),
                'game_files.encrypted' => DB::raw('game_file_meta.encrypted'),
                'game_files.disc_key' => DB::raw('game_file_meta.disc_key'),
            ]);

        Schema::dropIfExists('game_file_meta');
    }
};
