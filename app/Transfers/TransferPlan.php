<?php

declare(strict_types=1);

namespace App\Transfers;

/**
 * Everything one transfer writes, worked out by the server: the browser, or
 * FileTransferJob, only copies.
 */
final class TransferPlan
{
    /**
     * @param  list<PlannedFile>  $files
     * @param  string  $gamelist  relative to the drive's root, e.g. roms/psx/gamelist.xml
     */
    public function __construct(
        public readonly array $files,
        public readonly string $gamelist,
        public readonly int $games = 1,
    ) {}

    /**
     * Several games' plans as one: a console sent at once. They share a game
     * list, being one console's games.
     *
     * @param  list<TransferPlan>  $plans
     */
    public static function combine(array $plans, string $gamelist): self
    {
        return new self(
            array_merge(...array_map(fn (TransferPlan $plan): array => $plan->files, $plans)),
            $gamelist,
            count($plans),
        );
    }

    public function bytes(): int
    {
        return array_sum(array_map(fn (PlannedFile $file): int => $file->size, $this->files));
    }

    /** @return array{files: list<array{url: string, destination: string, size: int}>, gamelist: string, bytes: int, games: int} */
    public function toArray(): array
    {
        return [
            'files' => array_map(fn (PlannedFile $file): array => $file->toArray(), $this->files),
            'gamelist' => $this->gamelist,
            'bytes' => $this->bytes(),
            'games' => $this->games,
        ];
    }
}
