<?php

declare(strict_types=1);

namespace App\Transfers;

use App\Support\Console;
use App\Support\TransferRegions;

/**
 * The part of a transfer target that holds one transfer's choices.
 *
 * @phpstan-require-implements TransferTarget
 */
trait ChoosesOptions
{
    private ?TransferOptions $options = null;

    public function withOptions(TransferOptions $options): static
    {
        $target = clone $this;
        $target->options = $options;

        return $target;
    }

    public function options(): TransferOptions
    {
        return $this->options ?? TransferOptions::none();
    }

    /**
     * The first type of each slot worth fetching for: a slot that is not a
     * picture — a clip is several megabytes a game — is not recommended.
     *
     * @return list<string>
     */
    public function recommendedTypes(): array
    {
        $types = [];

        foreach ($this->artworkSlots() as $slot) {
            if (($slot['recommended'] ?? true) && isset($slot['types'][0])) {
                $types[] = $slot['types'][0];
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * The regions this transfer tries, in order.
     *
     * @return list<string>
     */
    protected function regionChain(?Console $console): array
    {
        return TransferRegions::chainFor($console, $this->options()->regions ?? []);
    }
}
