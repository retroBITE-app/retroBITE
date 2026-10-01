# 3. Transfers are written by the browser, on localhost

Date: 2026-09-24

## Status

Accepted. Amended by 0004: a USB drive is now one destination among
several, and network shares are written by the server. Amended by 0005:
the folders that mark a drive's root are the target's, not always `roms/`.

## Context

A game should go from the library onto a USB drive for another system —
Batocera first — straight from the web page, laid out the way that system
expects. retroBite runs in a container, often on a machine other than the one
in front of the person, and the project has no TLS certificate.

## Decision

The **browser writes the drive**, with the File System Access API
(`showDirectoryPicker`, streamed writes), and the server only decides what to
write.

- The server works out the plan — every file, its destination and size — and
  merges the target's game list (`App\Transfers`, one class per transfer
  target). All of the target's rules are PHP, under test.
- The browser reads the drive, streams each file from the server onto it, and
  writes the merged game list back (`resources/js/transfer.js`). No queue, no
  job: the tab does the copying.
- The API exists only in Chrome and Edge, and only in a secure context. With no
  certificate, that means the page opened through `localhost` on the machine
  running retroBite. Anywhere else the modal says so and links to
  `TRANSFER_LOCALHOST_URL`, which compose sets to the published port.
- Nothing on the drive is overwritten or removed except the game's own entry
  in the game list; a file already there and finished is skipped, and files
  are written straight to their own names, so an interrupted transfer resumes
  by writing again whatever was not finished. Finished is told from the
  folder's listing alone, never by asking each file its size — a memory card
  answers that slowly, one file at a time: a journal in the browser's
  IndexedDB holds each file from its first byte to its close, as a `.part`
  name would, and a `.crswap` in the listing marks one a crash left. The browser writes through a
  swap file and fills the name only on close, so an interrupted file is left
  empty (0 bytes, with a `.crswap` beside it), never half-written. A temporary
  name renamed afterwards was tried and dropped: Chromium allows `move()` on a
  drive only with a write grant on the new name or a click within the last few
  seconds, and a disc image is renamed minutes after the click, failing with
  `NotAllowedError`.
- A drive's Batocera tree is recognised, not assumed — `roms/` at the chosen
  folder, or under `batocera/` — because the external-drive layout is not
  documented; otherwise the person is asked before `roms/` is created.

## Considered options

- **A drive mounted into the container.** Direct and browser-independent, but
  the drive has to be in the server, and mounting removable media into Docker
  is fiddly on every host.
- **A zip to unpack.** Works in any browser over plain http, but is not "send to
  the drive", and doubles the disk work for a four-gigabyte disc.

## Consequences

- Transfers need Chrome or Edge, on the server's own machine (or a tunnel to its
  localhost). Firefox and Safari see an explanation instead.
- A new transfer target is a class and a line in `config/transfer.php`; the
  browser side does not change.

## Amendment: targets without a game list, and files made for the drive

Open PS2 Loader joined Batocera as a target. It changed three assumptions:

- **A target declares its root.** `TransferTarget::root()` lists the folders
  that mark where its tree starts — `roms/` and `batocera/roms/` for Batocera —
  and the browser looks for those instead of knowing Batocera's. OPL lists
  none: its `DVD/`, `CD/`, `CFG/` and `ART/` sit at the top of the drive.
- **A game list is optional.** A plan's `gamelist` is null for a system that
  keeps none, and nothing merges or writes one.
- **Not every file is copied from somewhere.** OPL's `CFG/<serial>.cfg` and
  `ART/<serial>_COV.png` are a target's *extras* (`TransferTarget::extras()`),
  made by the PS2 toolbox's own code when they are written and never stored,
  the way Batocera's `gamelist.xml` is: the browser fetches each from
  `transfers/{target}/games/{gameId}/extras/{path}` after the game's files,
  and `WriteTransferGamelist` writes them to a share. One already on the
  drive is left alone, since somebody may have tuned it there.

## Amendment: a game's other versions are replaced

A game holds every region and dump of a title, and a transfer sends one
version of it (`GameVersions`). Which one can change — a region chosen for a
send, a console's own region order, the provider's word on which dump is
played — and a drive sent the European copy last time would otherwise end up
holding both, listed twice.

- A plan carries `replaces`: where the game's *other* versions would be on the
  drive, laid out the same way — their files, their artwork named after them,
  for OPL their discs. Nothing the send itself writes is among them.
- They are removed only after the new version is on the drive: by the browser
  after its copies and extras, by `WriteTransferGamelist` on a share. A path
  that is not there is nothing to do.
- The merge drops their entries from the game list as it adds the new one.
- That is the one exception to "nothing on the drive is removed". It is
  narrow on purpose: only paths a transfer of this same game would have
  written, never anything else in the folder, never another game's.
