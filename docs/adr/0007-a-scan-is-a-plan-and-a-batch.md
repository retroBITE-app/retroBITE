# 7. A scan is a plan and a batch of chunks

Date: 2026-10-12

## Status

Accepted.

## Context

A scan was one job, `ScanConsoleFolder`, that walked a console's folder and
wrote every game and file row as it went. On a 900 GB PlayStation folder it
did two things wrong:

- **It ran out of memory before writing a row.** The walk kept a cloned
  `SplFileInfo` per file, about 1.4 kB each against 150 bytes for a path and a
  size; tens of thousands of files and the framework passed PHP's 128 MB, and
  the worker died with status 255 a fraction of a second in. Marking missing
  files would have been next: one `whereNotIn` with every path in the folder
  bound to it, past what one statement can carry.
- **Nothing could be seen while it ran.** One job for five or ten minutes is
  one line in the sidebar and nothing in the log until the end.

The passes cannot simply be split by folder or by file count: a playlist has
to claim its discs, and a cuesheet its tracks, before either is mistaken for a
loose game (the scan's passes, hardest first; see CLAUDE.md).

## Decision

**A scan is three kinds of job, all on `default`:**

1. **`ScanConsoleFolder` plans.** `LibraryScanner::plan()` walks the whole
   folder, reads every playlist and cuesheet, and runs the passes over
   it, writing nothing. The plan is a list of units, one per game: a
   playlist with its discs and their tracks, the discs of a set their names
   number ("(Disc 2)", added with docs/adr/0008), a sheet with its tracks, or
   one loose file. Each file is in one unit only. The planner marks what has gone
   (`markMissing`, compared in PHP and flagged a thousand at a time) and
   queues the rest as a job batch named `Scan {console}`.
2. **`ScanFolderChunk` records** about a hundred units: their games and file
   rows. One log line each, `Scan chunk recorded.` with `chunk: 12/300`.
3. **`FinishScan` runs once the batch is over**, failed chunks included:
   queues the lookups (`MatchGame::queueAwaiting`) and inspections, counts
   the folder again, and logs `Scan finished.` with how many chunks failed.

**Chunks may run at once**, and `QUEUE_WORKERS_DEFAULT` is two. Two writers
are kept apart by the plan: a path is in one unit, and titles that reduce to
the same slug ("Aladdin", "aladdin") go in one chunk, so two chunks never
both find a slug free. The rare suffixed slug another title happens to
reduce to is caught on the unique index and looked up again.

**A console is scanned once at a time.** The planner starts nothing while a
batch of the same name is unfinished in `job_batches`.

**What is watched is the queue.** The sidebar's Scanning row counts the
chunks down like any other job, `job_batches` holds the batch, and the log
says what each chunk did. There is no table of scans.

**A sheet's names are taken exactly as written.** A track or disc a
cuesheet or playlist names that is not there by that path — renamed,
different case — is skipped. It is the owner's to fix; the scan does not
guess at it. The fallback that matched on filename alone went through every
file in the folder for each miss.

## Considered options

- **A `scans` table**, one row per scan with its counts, a progress bar on
  the console card and in the sidebar. Built and taken out again: the batch
  and the queue's own count already say that a scan is working and how much
  is left, and a second record of the same thing was more to keep right than
  it was worth.
- **One job, with more memory.** Fixes the crash, keeps the five minutes of
  silence.
- **Chunks by folder.** Breaks the passes: a playlist naming `../` or a
  cuesheet beside its tracks in another folder would be cut in two.

## Consequences

- The planner still reads the whole folder, so it is the long job of a scan.
  How long depends on the disk: 11 000 PlayStation files took 14 seconds on a
  Mac reading its own SMB mount, and over three minutes from a container on
  Docker Desktop reading the same mount through Docker's file sharing.
  `docker-compose.smb.yml` mounts the share in the container instead.
- `retrobite:scan` without `--queue` still runs the whole scan in the
  command (`LibraryScanner::scan()`: plan, mark, record), with no batch.
- A chunk is tried once. One that fails leaves its games for the next scan,
  and the batch goes on without it.
- Tests that dispatch a scan cannot use `Bus::fake()`, which holds the batch
  and records nothing. `runScanJob()` in `tests/Pest.php` runs the planner,
  chunks and finish, and holds only what they queue.
