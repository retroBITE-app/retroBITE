# 8. Multi-disc games travel as a folder and a playlist

Date: 2026-10-12

## Status

Accepted.

## Context

Send to copied a game's files as the library had them. A multi-disc game with
its own `.m3u` arrived playable. Loose discs (`FF7 (Disc 1).cue`,
`FF7 (Disc 2).cue`, no playlist) arrived beside every other game, with the
game list pointing at the first disc. The second disc was on the drive, but
RetroArch's Disc Control had nothing to change to, and the front-end either
listed every disc or none past the first.

The scan had the same blind spot. Without a playlist each loose disc became a
game of its own, was looked up on its own, and was merged with the others only
after the provider had answered for every one of them.

The front-ends do not agree on how a multi-disc game should sit on the drive:

- **Batocera and Recalbox** hide the discs an `.m3u` names, but only when the
  playlist is beside them and names them by bare file name.
- **RetroPie** plays an `.m3u` but hides nothing.
- **ES-DE** shows a folder named like a file (`Game.m3u/`) as that one file,
  a "directory interpreted as file", and launches the playlist of the same
  name inside it.
- **Daijishō** reads no playlist at all.

Not every system plays an `.m3u` either. PlayStation, Saturn, Mega-CD and
Dreamcast do on all four. PC Engine CD does on ES-DE and Recalbox only. 3DO,
Neo Geo CD and PlayStation 2 do not anywhere.

Each front-end recommends putting single-disc games loose, as one `.chd` or a
`.cue` with its tracks. RetroArch also names saves after the file it launches,
so giving a single disc a playlist would leave every existing save behind.

## Decision

**A version of two discs or more, on a console the target lists in
`playlistConsoles()`, goes into a folder of its own with a playlist written
for it.** The list points at the playlist, and the artwork is named after it.

- The folder is named for the set: the library's playlist without its
  extension, or the first disc's name without "(Disc N)". ES-DE's folder is
  that name plus `.m3u` (`setFolder()`), and its list points at the folder
  (`setEntry()`).
- The files keep their arrangement under the folder they share in the library.
- The playlist names each disc's sheet, or its single image, one per line,
  relative to the playlist, in disc order, with LF line endings. It never
  names a `.bin`.
- The playlist is a target **extra**, like OPL's config. It is made when it is
  written and never stored in the library. The library's own playlist is not
  copied, because it names the discs where they lie in the library, not on the
  drive.
- Single discs, and consoles not in the target's list, go as before.

**The scan numbers discs by name.** Unclaimed files in one folder, with one
extension, alike but for a bracketed "(Disc N)" (`DiscOrder::set()`), are one
game. Sheets are grouped before loose cuesheets are read, and single images
after them, so a track is never taken for a disc.

## Consequences

- A drive sent before this change holds the set loose. The next send lists the
  loose discs, their artwork and their list entry among what it replaces, and
  removes them once the folder has arrived.
- Nothing is written into the library. Somebody who wants a playlist there
  still has the console toolbox's "game folders" export.
- Extras are never written over. A stale playlist could therefore stay on the
  drive, but its content follows from the folder name, and another version's
  folder has another name.
- Which consoles play a playlist is one list per target. A front-end that adds
  `.m3u` to a system needs one line.
