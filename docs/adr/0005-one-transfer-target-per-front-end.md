# 5. One transfer target per front-end, not one layout for all

Date: 2026-09-29

## Status

Accepted.

## Context

Send to wrote one layout, Batocera's. Recalbox, RetroPie, ES-DE and Daijishō
were asked for next. All five read an EmulationStation `gamelist.xml`, which
suggested that one folder structure could serve them all, with the list saying
where everything is. It cannot:

- Batocera, Recalbox and RetroPie follow the list's artwork paths, but the tags
  mean different things. Batocera shows the box in `<thumbnail>` and a
  screenshot in `<image>`; Recalbox and RetroPie show `<image>` as the main
  picture.
- ES-DE ignores both the artwork tags and a `gamelist.xml` in the ROM folder.
  It finds artwork by file name under `downloaded_media/{system}/{kind}/` and
  keeps its lists in `gamelists/{system}/`. On Android both are set up inside
  the `roms/` folder, beside the systems, and that is the layout the ES-DE
  target writes, labelled "ES-DE (Android)". EmulationStation on Batocera
  has no `downloaded_media`; its `images/` and `videos/` are in each system
  folder, which is the Batocera target.
- Daijishō reads no artwork paths at all. Artwork is imported by hand, one
  folder per kind, matched to the ROM by name. From a list it takes only the
  name, description and genre.
- A few system folders are named differently in each front-end: GameCube is
  `gamecube` in Batocera and Recalbox and `gc` in RetroPie and ES-DE.

## Decision

Each front-end gets a **target of its own** (`App\Transfers\*Target`,
registered in `config/transfer.php`), and the person chooses one under
"Laid out for" when sending. Each target writes the layout its front-end's own
scraper would, so what arrives looks like something the front-end made itself.

The targets share one base, `GamelistTarget`. It chooses the version, lays out
the game's files, picks the artwork and merges the list. A target provides only
its ROM folder, its system names, its artwork (folder, file name and tag) and,
if it differs, where its list goes and what the list holds.

The browser no longer assumes `roms/`. Each target names the folders that mark
where its layout starts on a drive (`roots()`), and anything the person must do
in the front-end is shown in the modal as a hint (`hint()`).

## Consequences

- Adding a front-end means one class and one line in config. The browser, the
  share path and the jobs do not change.
- Sending one game to two front-ends makes two copies with two layouts. That
  is intended: the same drive is rarely read by both.
- Only artwork already downloaded is sent. A front-end that wants a type the
  library never fetched gets that tag left out, not an error.
- The system-name tables came from each front-end's own list at the time of
  writing (Batocera's `es_systems.yml`, ES-DE's `es_systems.xml`, RetroPie's
  `platforms.cfg`, and the system folders in Recalbox's repository). A console
  missing from a table keeps its key.
