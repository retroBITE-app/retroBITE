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
     * @param  string|null  $gamelist  relative to the drive's root, e.g. roms/psx/gamelist.xml;
     *                                 null for a system that keeps none
     * @param  list<array{url: string, destination: string}>  $extras  the target's own
     *                                                                 files, see TransferTarget::extras()
     * @param  list<string>  $replaces  where the game's other versions would be on the drive:
     *                                  removed, where they are, once this version has arrived
     */
    public function __construct(
        public readonly array $files,
        public readonly ?string $gamelist,
        public readonly int $games = 1,
        public readonly array $extras = [],
        public readonly array $replaces = [],
    ) {}

    /**
     * Several games' plans as one: a console sent at once. They share a game
     * list, being one console's games.
     *
     * @param  list<TransferPlan>  $plans
     */
    public static function combine(array $plans, ?string $gamelist): self
    {
        return new self(
            array_merge(...array_map(fn (TransferPlan $plan): array => $plan->files, $plans)),
            $gamelist,
            count($plans),
            array_merge(...array_map(function (TransferPlan $plan): array {
                return $plan->extras;
            }, $plans)),
            array_merge(...array_map(fn (TransferPlan $plan): array => $plan->replaces, $plans)),
        );
    }

    public function bytes(): int
    {
        return array_sum(array_map(fn (PlannedFile $file): int => $file->size, $this->files));
    }

    /** @return array{files: list<array{url: string, destination: string, size: int}>, extras: list<array{url: string, destination: string}>, replaces: list<string>, gamelist: string|null, bytes: int, games: int} */
    public function toArray(): array
    {
        return [
            'files' => array_map(fn (PlannedFile $file): array => $file->toArray(), $this->files),
            'extras' => $this->extras,
            'replaces' => $this->replaces,
            'gamelist' => $this->gamelist,
            'bytes' => $this->bytes(),
            'games' => $this->games,
        ];
    }
}
