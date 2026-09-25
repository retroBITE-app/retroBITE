<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\GameStatus;
use App\Events\SystemUpdated;
use App\Models\GameFile;
use App\Support\Scanning\LibraryFolders;
use Illuminate\Support\Facades\Cache;

/**
 * How much room the identified games take, and how much the disk has left.
 *
 * Deliberately not the device's own used figure. A retroBITE host is usually
 * somebody's desktop, where the disk is mostly an operating system and years of
 * other things: reporting that back says the drive is 84 % full and says
 * nothing whatever about the library. The number worth showing is what the
 * games occupy.
 *
 * Summed from the database, and only for games the provider has identified —
 * the same "games" the console cards, the shelf and the dashboard count. It
 * used to walk the library folder so it could include loader artwork, configs
 * and anything copied in over the share; that meant a page could end up reading
 * the whole disk, and no page does disk work now. What a scan has not imported,
 * or the provider has not named, is not in this figure.
 *
 * The denominator is that plus whatever is free on the device, so the bar still
 * fills as the disk does. The free space is the one reading that needs the
 * disk, and MeasureLibrary takes it in the background and leaves it here.
 */
final class LibraryStorage
{
    /** The free-space reading MeasureLibrary last left, or false when the root was unreadable. */
    private const FREE_KEY = 'library.free';

    /**
     * @param  int  $used  bytes the identified games' files take
     * @param  int  $free  bytes still free on the device the library sits on
     */
    public function __construct(
        public readonly int $used,
        public readonly int $free,
    ) {}

    /**
     * What the games take and what is left, or null when that is not known.
     *
     * Null rather than zeros, as ScreenScraperQuota does: a share that is not
     * mounted — or has not been measured since the app started — and an empty
     * library are not the same answer, and only one of them should draw a bar.
     *
     * One query and one cache read. No filesystem.
     */
    public static function current(): ?self
    {
        $free = Cache::get(self::FREE_KEY);

        if (! is_int($free)) {
            return null;
        }

        return new self(used: self::usedByIdentifiedGames(), free: $free);
    }

    /**
     * Take the free-space reading. Disk work, so MeasureLibrary's alone.
     *
     * disk_free_space() announces an unreadable path twice: a warning, and
     * false. The warning is the dangerous one — Laravel's bootstrapped handler
     * promotes a plain E_WARNING into a thrown ErrorException — so it is
     * silenced for exactly that call, as CoverArt::encode() does.
     */
    public static function measureFree(): void
    {
        $root = LibraryFolders::root();
        $free = false;

        if ($root !== '' && is_dir($root)) {
            set_error_handler(static fn (): bool => true);

            try {
                $free = disk_free_space($root);
            } finally {
                restore_error_handler();
            }
        }

        // Kept until the next reading, not for a minute: nothing but the next
        // measurement can say it changed.
        Cache::forever(self::FREE_KEY, $free === false ? false : (int) $free);
    }

    /**
     * The library may have changed size: tell the sidebar so it reads again.
     *
     * The used figure is a query, so there is nothing cached to drop.
     */
    public static function changed(): void
    {
        LiveUpdates::system(SystemUpdated::STORAGE);
    }

    /** What the library could grow to without anything else being deleted. */
    public function total(): int
    {
        return $this->used + $this->free;
    }

    /** Every present file of every identified game, in bytes. */
    private static function usedByIdentifiedGames(): int
    {
        return (int) GameFile::query()
            ->join('games', 'games.id', '=', 'game_files.game_id')
            ->where('games.status', GameStatus::Matched)
            ->whereNull('game_files.missing_since')
            ->sum('game_files.size_bytes');
    }
}
