# 4. One job moves and copies files; network shares are destinations

Date: 2026-09-25

## Status

Accepted. Amends 0003: a USB drive is still written by the browser, but it is
now one destination among several.

## Context

Files are moved in three places — a game between its layout's folders, a
finished upload out of staging, and (0003) a game onto a USB drive — and each
did it its own way. The next destination is a network share on another
machine, a Batocera box's `\\BATOCERA\share` above all, which the server can
reach and the browser cannot. The person should not have to know its address.

## Decision

**`App\Jobs\FileTransferJob` is the only thing that moves or copies a file on
the server.** It takes a list of transfers — a source location, a destination
location — and a mode, copy or move, and:

- checks everything before it writes anything: every source a plain, readable
  file; every destination folder writable, proven by writing and removing a
  probe file; room enough where the free space can be read; no name taken
  twice;
- never writes over a file. A copy finding the same name at the same size
  skips it, so an interrupted transfer resumes; any other clash refuses the
  whole list. A move refuses on any clash;
- writes under a temporary name and renames when the size checks out;
- is all or nothing: a failure part way removes the copies it made and moves
  back the files it moved, and only deletes a moved file's source once every
  copy is in place.

Moves inside the library are a rename and finish at once, so the game page and
the uploader run the job in the request (`FileTransferJob::now()`) and keep
their answer. Copies to a share run queued, on `transfer` over `database-long`,
because a four-gigabyte disc does not copy in 90 seconds.

**A transfer is a game, a target and a destination.** The target (Batocera)
still decides the layout and the game list (`App\Transfers\TransferTarget`,
0003). The destination is where it goes:

- **This computer's USB drive** — written by the browser as before, with no
  job, because the drive is not on the server.
- **A network share** — a saved `Destination`, written by the job through
  libsmbclient (the `smbclient` PHP extension, by way of icewind/smb). Only
  copies: nothing is moved out of the library.

**Shares are found, not typed.** A search asks four ways and merges the
answers: mDNS for `_smb._tcp`, NetBIOS and plain DNS for known names such as
`batocera`, and a scan of the LAN's /24 for the SMB port, each machine that
answers then asked its NetBIOS name directly. The shares on a host are listed
for the person to pick. Typing a name or an address remains, for when nothing
answers. Searching and listing are queued, like every other network call a
page could wait on.

The scan is there because the first three do not work behind Docker's
bridge — tried on a Mac with a Batocera box on the LAN, none of them answered,
while a connection to the box's address went straight through. The container
cannot see which network is the LAN, so it scans only one it is told of:
`TRANSFER_DISCOVERY_SUBNETS`, else the network `HOST_IP` is on, else the one
the page was opened on. Never a public address, never more than a /24, and
only when somebody presses the button.

## Considered options

- **A job per file.** Simpler, but a game is several files that only work
  together; losing all-or-nothing loses the guarantee `moveGame` had.
- **Mounting the share with CIFS.** Needs `SYS_ADMIN` in the web container and
  a mount per destination. libsmbclient needs neither.
- **Driving the `smbclient` command.** What icewind/smb falls back to without
  the extension, and what the first version did. Its parser of the command's
  text falls out of step after a long directory listing — a Batocera
  `images/` folder of a few thousand files — and reads the next command's
  answer as the rest of it, so a console sent at once failed on most of its
  games.
- **Host networking for the web container by default.** Would let mDNS and
  NetBIOS work on Linux, but breaks the compose network the database is
  reached over, and does nothing on Docker Desktop. The scan works on both.

## Consequences

- The ROM library is written in exactly two ways now: by `FileTransferJob`,
  and by deleting a file from the game page. `LibraryPath` remains the gate
  both go through.
- mDNS and NetBIOS only see the LAN when the web container does — on Linux
  with `network_mode: host`. Behind Docker's bridge the search relies on the
  scan, which needs `HOST_IP` set (or the page opened on the LAN address);
  without it the page says so.
- A share's password is stored encrypted, like the provider credentials, and
  jobs carrying one are encrypted on the queue.
