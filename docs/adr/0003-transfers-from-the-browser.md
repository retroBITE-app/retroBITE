# 3. Transfers are written by the browser, on localhost

Date: 2026-09-24

## Status

Accepted. Amended by 0004: a USB drive is now one destination among
several, and network shares are written by the server.

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
  in the game list; a file already there at the same size is skipped, and files
  are written under a temporary name and renamed when complete, so an
  interrupted transfer resumes cleanly.
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
