<?php

declare(strict_types=1);

namespace App\Transfers;

use App\Support\MediaRegions;
use Illuminate\Http\Request;

/**
 * What somebody chose for one transfer beyond where it goes and how it is
 * laid out: the order to try regions in, and which of the target's artwork
 * to send at all.
 *
 * Neither is the target's to decide, so neither is a setting: the same
 * console can go to one drive in its European versions with every picture
 * and to another in its Japanese ones with the boxes alone. Nothing chosen —
 * the default — is the console's region order and every piece of artwork
 * there is.
 *
 * Carried in the plan's URLs for a drive, and on the Transfer row for a
 * share, where every job that re-plans a game reads it back.
 */
final readonly class TransferOptions
{
    /**
     * @param  list<string>|null  $regions  the regions to try first, in order; null for the console's order alone
     * @param  list<string>|null  $artwork  the artwork slots to send; null for every one
     */
    public function __construct(
        public ?array $regions = null,
        public ?array $artwork = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * What a plan's URL asks for, with anything the app or the target does
     * not know dropped rather than refused: a stale link still plans.
     */
    public static function fromRequest(Request $request, ?TransferTarget $target = null): self
    {
        // Present but empty is no artwork at all, and arrives as null once
        // empty strings are converted: the key is what says it was chosen.
        $artwork = $request->has('artwork') ? explode(',', (string) $request->query('artwork')) : null;
        $regions = $request->query('regions');

        return self::fromArray([
            'regions' => is_string($regions) ? explode(',', $regions) : null,
            'artwork' => $artwork,
        ], $target);
    }

    /**
     * @param  array<string, mixed>|null  $options  as toArray() wrote them
     */
    public static function fromArray(?array $options, ?TransferTarget $target = null): self
    {
        $regions = $options['regions'] ?? null;

        // A row written when one transfer could choose one region.
        if ($regions === null && is_string($options['region'] ?? null)) {
            $regions = [$options['region']];
        }

        if (is_array($regions)) {
            $known = MediaRegions::labels();
            $regions = array_values(array_unique(array_filter(
                array_map(fn (mixed $region): string => strtolower(trim((string) $region)), $regions),
                fn (string $region): bool => array_key_exists($region, $known),
            )));
            $regions = $regions !== [] ? $regions : null;
        } else {
            $regions = null;
        }

        $artwork = $options['artwork'] ?? null;

        if (is_array($artwork)) {
            $slots = $target !== null ? array_keys($target->artworkSlots()) : null;
            $artwork = array_values(array_unique(array_filter(
                array_map(strval(...), $artwork),
                fn (string $slot): bool => $slot !== '' && ($slots === null || in_array($slot, $slots, true)),
            )));
        } else {
            $artwork = null;
        }

        return new self($regions, $artwork);
    }

    /** Whether one of the target's artwork slots is to be sent. */
    public function sends(string $slot): bool
    {
        return $this->artwork === null || in_array($slot, $this->artwork, true);
    }

    /**
     * For the Transfer row; null keys are kept so a row says what it was.
     *
     * @return array{regions: list<string>|null, artwork: list<string>|null}
     */
    public function toArray(): array
    {
        return ['regions' => $this->regions, 'artwork' => $this->artwork];
    }

    /**
     * For a URL: only what was chosen. An empty artwork list is a choice —
     * no artwork — and goes as an empty value, not as nothing.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return array_filter([
            'regions' => $this->regions !== null ? implode(',', $this->regions) : null,
            'artwork' => $this->artwork !== null ? implode(',', $this->artwork) : null,
        ], fn (?string $value): bool => $value !== null);
    }
}
