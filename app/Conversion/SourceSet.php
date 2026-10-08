<?php

declare(strict_types=1);

namespace App\Conversion;

use App\Enums\FileRole;
use App\Models\ConsoleSourceFolder;
use App\Models\Game;
use App\Models\GameFile;
use App\Support\Console;
use App\Support\Scanning\DiscOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One thing a conversion can be asked for: a single disc, or a whole
 * multi-disc set in one job.
 *
 * Built from the rows the scanner already wrote, which already know how files
 * belong together: a playlist is the parent of its discs, a cue sheet of its
 * tracks. A game of several discs that no playlist lists is recognised the way
 * the playlist writer recognises one — every loose disc in one folder saying
 * "(Disc N)" in its name — so it converts as a set too and gets a playlist of
 * its own out of it.
 */
final class SourceSet
{
    /**
     * @param  list<Disc>  $discs  in disc order
     * @param  string  $directory  where the discs are, inside the console's folder; '' for the folder itself
     */
    public function __construct(
        public readonly Console $console,
        public readonly GameFile $file,
        public readonly ?GameFile $playlist,
        public readonly array $discs,
        public readonly string $directory,
        public readonly ?Game $game,
    ) {}

    /**
     * Everything convertible on a console, one entry per set, ordered by name.
     *
     * @return Collection<int, self>
     */
    public static function forConsole(Console $console): Collection
    {
        $files = GameFile::query()
            ->present()
            ->onConsole($console->key)
            ->with('game')
            ->orderBy('path')
            ->get();

        return self::byName($files
            ->groupBy('game_id')
            ->flatMap(function (Collection $gameFiles) use ($console): array {
                return self::setsOf($console, $gameFiles);
            }));
    }

    /**
     * Everything convertible of one game, one entry per set, ordered by name:
     * what the game page's Conversion tab lists.
     *
     * @return Collection<int, self>
     */
    public static function forGame(Game $game): Collection
    {
        return self::fromFiles($game, $game->files()->present()->orderBy('path')->get());
    }

    /**
     * One game's sets from rows already to hand — the game page has them
     * loaded — so asking costs no query. Rows gone from disk are left out.
     *
     * @param  Collection<int, GameFile>  $files
     * @return Collection<int, self>
     */
    public static function fromFiles(Game $game, Collection $files): Collection
    {
        $console = $game->console();

        if ($console === null) {
            return collect();
        }

        $present = $files
            ->filter(function (GameFile $file): bool {
                return $file->isPresent();
            })
            ->sortBy('path')
            ->values()
            ->each(function (GameFile $file) use ($game): void {
                $file->setRelation('game', $game);
            });

        return self::byName(collect(self::setsOf($console, $present)));
    }

    /**
     * The set a picked row belongs to, worked out afresh from what is on
     * record; null for a row whose game has gone.
     */
    public static function fromFile(GameFile $file): ?self
    {
        $game = $file->getRelationValue('game');

        if (! $game instanceof Game) {
            return null;
        }

        return self::forGame($game)->first(function (self $set) use ($file): bool {
            return $set->contains($file);
        });
    }

    public function isSet(): bool
    {
        return count($this->discs) > 1;
    }

    /** @return list<string> every disc's extension, once each */
    public function extensions(): array
    {
        return array_values(collect($this->discs)
            ->map(function (Disc $disc): string {
                return $disc->extension();
            })
            ->unique()
            ->all());
    }

    /**
     * Every row that goes when the source is not kept: the playlist, each
     * disc, and each disc's tracks.
     *
     * @return list<GameFile>
     */
    public function files(): array
    {
        $files = $this->playlist !== null ? [$this->playlist] : [];

        foreach ($this->discs as $disc) {
            array_push($files, ...$disc->members);
        }

        return $files;
    }

    /** @return list<string> relative to the library root */
    public function paths(): array
    {
        return array_map(function (GameFile $file): string {
            return $file->path;
        }, $this->files());
    }

    public function contains(GameFile $file): bool
    {
        return collect($this->files())->contains('id', $file->id);
    }

    public function bytes(): int
    {
        return (int) collect($this->discs)->sum(function (Disc $disc): int {
            return $disc->bytes();
        });
    }

    /** What the source list calls it: the playlist, or the one file, by name. */
    public function label(): string
    {
        return $this->playlist !== null ? $this->playlist->filename : $this->discs[0]->file->filename;
    }

    /**
     * What a playlist written for the set is named, without the extension:
     * the old playlist's name, else the game's folder on a layout that has
     * one, else the first disc's name with its disc number taken out.
     */
    public function stem(): string
    {
        if ($this->playlist !== null) {
            return pathinfo($this->playlist->filename, PATHINFO_FILENAME);
        }

        if ($this->directory !== '') {
            return basename($this->directory);
        }

        $stem = DiscOrder::strip($this->discs[0]->stem());

        return $stem !== '' ? $stem : $this->discs[0]->stem();
    }

    /**
     * Sets in the order a list shows them: naturally by name, ignoring case,
     * so "Disc 10" comes after "Disc 9".
     *
     * @param  Collection<int, self>  $sets
     * @return Collection<int, self>
     */
    private static function byName(Collection $sets): Collection
    {
        return $sets
            ->sortBy(function (self $set): string {
                return $set->label();
            }, SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * Group one game's rows into sets: each playlist with its discs, then the
     * loose numbered discs of one folder together, then everything else alone.
     *
     * @param  Collection<int, GameFile>  $files
     * @return list<self>
     */
    private static function setsOf(Console $console, Collection $files): array
    {
        $root = (string) ConsoleSourceFolder::pathFor($console);
        $children = $files->groupBy('parent_id');
        $sets = [];

        $discOf = function (GameFile $file, int $number) use ($children): Disc {
            $tracks = $file->role === FileRole::Sheet
                ? $children->get($file->id, collect())->filter(function (GameFile $child): bool {
                    return $child->role === FileRole::Track;
                })->values()->all()
                : [];

            return new Disc($file, [$file, ...$tracks], $number);
        };

        foreach ($files->where('role', FileRole::Playlist) as $playlist) {
            $discs = $children->get($playlist->id, collect())
                ->filter(function (GameFile $file): bool {
                    return in_array($file->role, [FileRole::Sheet, FileRole::Rom], true);
                })
                ->sort(function (GameFile $a, GameFile $b): int {
                    return ((int) $a->disc_number <=> (int) $b->disc_number) ?: DiscOrder::compare($a->filename, $b->filename);
                })
                ->values();

            if ($discs->isNotEmpty()) {
                $sets[] = self::make($console, $root, $playlist, $playlist, array_values($discs->map($discOf)->all()));
            }
        }

        // Loose: not a playlist's disc, not a sheet's track.
        $loose = $files->filter(function (GameFile $file): bool {
            return $file->parent_id === null && in_array($file->role, [FileRole::Sheet, FileRole::Rom], true);
        });

        foreach ($loose->groupBy(function (GameFile $file): string {
            return dirname($file->path).'|'.Str::lower((string) $file->extension);
        }) as $group) {
            $numbered = $group->count() > 1 && $group->every(function (GameFile $file): bool {
                return DiscOrder::numbered($file->filename);
            });

            if ($numbered) {
                $discs = $group->sort(function (GameFile $a, GameFile $b): int {
                    return DiscOrder::compare($a->filename, $b->filename);
                })->values();

                $sets[] = self::make($console, $root, $discs->first(), null, array_values($discs->map($discOf)->all()));

                continue;
            }

            foreach ($group as $file) {
                $sets[] = self::make($console, $root, $file, null, [$discOf($file, 0)]);
            }
        }

        return $sets;
    }

    /** @param  list<Disc>  $discs */
    private static function make(Console $console, string $root, GameFile $file, ?GameFile $playlist, array $discs): self
    {
        $relative = Str::after(($playlist ?? $discs[0]->file)->path, $root.'/');
        $directory = dirname($relative);

        return new self(
            console: $console,
            file: $file,
            playlist: $playlist,
            discs: $discs,
            directory: $directory === '.' ? '' : $directory,
            game: $file->relationLoaded('game') ? $file->game : null,
        );
    }
}
