<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DocLink;
use App\Models\Game;
use App\Resources\DocResource;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Which docs are about which games — the only code that reads or writes
 * doc_links.
 *
 * A doc is a file and a game is a row, so the two drift apart in ways the
 * database cannot see: a doc deleted from the Docs page goes through here, and
 * one removed by hand on the disk is noticed the next time its game's docs are
 * read. A game deleted takes its links with it by cascade; a game merged into
 * another hands them over with repoint().
 */
final class DocLinks
{
    public function __construct(private readonly DocLibrary $library) {}

    /** Say a doc is about a game; saying it twice changes nothing. */
    public function link(string $path, Game $game): void
    {
        if ($this->library->find($path) === null) {
            throw new InvalidArgumentException('No such document: '.$path);
        }

        DocLink::query()->firstOrCreate(['doc_path' => $path, 'game_id' => $game->id]);
    }

    public function unlink(string $path, Game $game): void
    {
        DocLink::query()->where('doc_path', $path)->where('game_id', $game->id)->delete();
    }

    /**
     * The docs about a game, newest first as the Docs page lists them. A link
     * whose file is gone is deleted rather than shown.
     *
     * @return Collection<int, DocResource>
     */
    public function docsFor(Game $game): Collection
    {
        $paths = DocLink::query()->where('game_id', $game->id)->pluck('doc_path');
        $docs = $this->library->search()->whereIn('path', $paths)->values();
        $gone = $paths->diff($docs->pluck('path'));

        if ($gone->isNotEmpty()) {
            DocLink::query()->where('game_id', $game->id)->whereIn('doc_path', $gone->all())->delete();
        }

        return $docs;
    }

    /**
     * The games a doc is about, by title, with what a chip shows.
     *
     * @return Collection<int, Game>
     */
    public function gamesFor(string $path): Collection
    {
        return Game::query()
            ->whereIn('id', DocLink::query()->where('doc_path', $path)->select('game_id'))
            ->select(['id', 'console', 'slug', 'title'])
            ->orderBy('title')
            ->get();
    }

    /** A doc is gone: so are its links. */
    public function forgetDoc(string $path): void
    {
        DocLink::query()->where('doc_path', $path)->delete();
    }

    /**
     * Hand one game's links to another, as a merge folds the first into the
     * second. A doc already linked to both keeps one link. The paths are read
     * first: MariaDB refuses an update that subqueries the table it updates.
     */
    public function repoint(Game $from, Game $to): void
    {
        $held = DocLink::query()->where('game_id', $to->id)->pluck('doc_path')->all();

        DocLink::query()
            ->where('game_id', $from->id)
            ->whereNotIn('doc_path', $held)
            ->update(['game_id' => $to->id]);

        DocLink::query()->where('game_id', $from->id)->delete();
    }
}
