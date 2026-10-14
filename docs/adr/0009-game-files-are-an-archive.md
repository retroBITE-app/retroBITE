# 9. Game files are an archive

Date: 2026-10-13

## Status

Accepted.

## Context

A game file's row is the only record of which dump the file was: its crc,
md5 and sha1, the name the provider and Redump know it by, the provider's
region, scrape count and flags, and the serial the toolbox read off the disc.

Three things deleted that row along with the file. A conversion with
keep-source off deleted its source. Deleting a file on the game page deleted
it. Pruning deleted the rows of files missing past the cutoff.

A conversion's output is a dump nobody has catalogued. A CHD's checksums are
its own, its name ends in `.chd`, and its size is not the image's. Once its
source's row was gone:

- the game could never be matched again by name or checksum;
- the nightly dump backfill (`RecordProviderDumps`) could never find the file
  among the provider's dumps;
- Send to lost the scrape count it prefers the popular dump by;
- a PlayStation 2 CHD lost its serial, because the toolbox does not read CHD.

Decrypting a PS3 image in place lost the same facts on the row it kept, since
the swap cleared them.

RetroAchievements was never affected. Its hash is of the disc's content and
is the same for a CHD.

## Decision

**A file removed on purpose is archived, not deleted.** `game_files` uses
Laravel's soft deletes. `archived_reason` says why the file went:

- `converted`: its source was not kept;
- `decrypted`: it was replaced in place;
- `deleted`: by hand on the game page;
- `pruned`: missing past the cutoff.

Every Eloquent query sees live files only. The few raw queries over
`game_files` say so themselves.

**A file made out of another points at it** (`source_id`):

- A conversion's output points at its source disc's root, its sheet or its
  image. The tracks hang off the sheet as they always have. A new playlist
  points at the old one.
- A swap in place archives a copy of the row with everything it knew. The
  live row, its facts cleared, points at the copy.
- `GameFile::origin()` follows the chain back to the first file, or that
  file's first track, which is what the provider indexes.

The matcher asks about a file with a source as its origin first: its name,
size and checksums. `ProviderDumps` recognises a file by its origin's keys as
well as its own.

**A conversion hashes a source with no checksums before archiving it.** An
archived row without checksums knows its dump only by name. Reading the
source once more is short beside the conversion that has just read it.

**What is known for certain is also copied to the output**: region, scrape
count and flags, serial and video mode. Send to and the game page read them
off the file without following the chain.

**A path is unique among live files only.** MariaDB has no partial index. The
unique index is therefore on a generated column, `live_path`, which holds the
path while `deleted_at` is null and NULL otherwise. A file can come back
where an archived one was. If the scan finds the same size at that path, it
restores the archived row with its checksums. At another size it is another
file and gets a row of its own.

**Deleting a game deletes its archive.** The history belongs to the game: a
pruned game and its archived files go together, and "Forget" on the game page
deletes one archived row for good.

## Consequences

- `missing_since` keeps its meaning. A missing file may come back, an archived
  one was removed. A pruned file is both.
- The Files tab shows where a file came from ("Converted from …"). It folds
  out the archived files beneath it, and lists the rest under a history the
  person asks for.
- `DB::table('game_files')` and joins on it do not see the soft-delete scope.
  A new raw query has to exclude archived rows itself.
- Rows are small, so the table grows by a few rows per conversion or
  deletion, which is nothing beside the library.
- Sources deleted before this change are gone. Nothing can bring their rows
  back.
