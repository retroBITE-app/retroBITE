# Conversion tools: what ships, and what could come next

Research behind the first conversion toolset (PS1 and PS2) and the ones after
it. The constraint for every tool: it must run **headless on Alpine (musl)**
inside the web container, driven as a command line by a queue worker. Checked
2026-09-28; entries marked *unverified* were not confirmed from source or a
build.

## Shipped in the web image

All are built from pinned tags in the `converters` stage of `Dockerfile.web`
(Alpine packages none of them on a stable branch).

| Tool | Version | Licence | Formats | Progress when piped | Notes |
|---|---|---|---|---|---|
| **chdman** (MAME) | mame0289 | GPL-2.0-or-later | CHD ⇄ cue/bin, gdi, toc, nrg (createcd/extractcd); CHD ⇄ ISO (createdvd/extractdvd); `verify`, `info` | Yes: `Compressing, 45.2% complete...` on stderr, `\r`-separated | Built as the `chdman` target only, with Alpine's musl patch for bx. Links SDL2 (MAME's OSD core), FLAC, zstd, utf8proc. Refuses to overwrite without `-f`. Reads a cue's `FILE` as relative to the cue, even when absolute. |
| **maxcso** | v1.13.0 | ISC (vendored 7-zip LGPL, zopfli Apache-2.0, libdeflate MIT) | ISO → CSO v1/v2, ZSO, DAX; `--decompress` back to ISO | No: only when stderr is a TTY | The runner reads its offset in the source from `/proc/<pid>/fdinfo` instead. Prints its version to stderr and exits 1. Overwrites silently. |
| **ecm / unecm** (kidoz fork) | v1.3.4 | GPL-2.0 | raw CD image ⇄ ECM | No | Maintained fork of Neill Corlett's tools, C23 and meson. Overwrites silently. `unecm --cue` can write a sheet. |
| **cue2pops** (makefu/cue2pops-linux, krHACKen's v2.0) | commit `3f2be61` | none stated (AUR: GPL-2.0) | PS1 cue → POPStarter VCD (1 MiB header + the BIN unchanged) | No | **Exits 1 on success, 0 on failure** (`success_exit`); errors on stdout. One `FILE … BINARY` per sheet and `TRACK 01 MODE2/2352` exactly — a split Redump dump is merged first with chdman createcd + extractcd in staging. Flags `gap++`/`gap--`, `vmode`, `trainer` are Advanced options. |
| **pops2cue** (bucanero's rebuild of krHACKen's v1.0) | commit `981e776` | GPL-3.0 (a decompiled rebuild; the claim is shaky) | POPStarter VCD → cue + one BIN | No | Takes no output path: writes beside its input, and only names them right for an uppercase `.VCD`, so it is handed a link in staging. Built with `-fsigned-char` — it compares bytes against negative literals, which breaks where `char` is unsigned (arm64). |
| **nodtool** (encounter/nod) | v2.0.0-alpha.12 | MIT OR Apache-2.0 | GameCube / Wii: ISO, GCM, RVZ, WIA, WBFS, CISO, GCZ (read and write); NFS read | No: indicatif draws only on a terminal | The release's static musl binary, fetched and checked against a pinned sha256 — nothing to build. The stable 1.x only writes ISO. Output format from the output's **extension**. Every convert and verify checks the disc against Redump DATs built into the binary, and a hash mismatch prints `❌ (expected: …)` **with exit 0** — so the verify step reads the output (`verifyFailed()`). WBFS and CISO are always written NKit-2 lossless (junk left out, rebuilt going back to ISO); `--scrub` drops the Wii update partition. Paths are printed without their leading `/`. |
| **extract-xiso** (XboxDev) | build-202609111233 (v2.7.1) | BSD-style (custom) | Xbox ISO → files, files → XISO, rewrite (`-r`) | Yes, on stdout | Installed and detected, not yet used: the Xbox toolset. Compiles on musl despite its LFS64 defines. |

The OpenROM project (<https://github.com/M5Devs/OpenROM>) was the reference for
the conversion matrix and for how each tool is invoked. It is now a Flutter/Dart
app with a headless `openrom-core` CLI. It is GPL-3.0, less than two months
old, and releases several times a week. It is not a dependency, for four
reasons:

- its Linux builds and the tool binaries it bundles are glibc, and the
  runtime here is musl;
- it is too young and moves too fast to pin;
- its own version pins disagree with upstream: it lists maxcso "1.4.0" and ecm
  "2.0.0", neither of which exists;
- a second tool layer would hide the progress output and the process to
  cancel.

What we took from it: `createcd` for PS1 ISO/IMG, `createdvd` for PS2 DVD
images, and the percentage regex. An `openrom` converter wrapping
`openrom-core` would fit the framework unchanged, if a format ever has no
standalone Linux tool.

## Candidates for later toolsets

| Tool | Formats | Consoles | Licence | Headless on Alpine? |
|---|---|---|---|---|
| **dolphin-tool** (Dolphin) | `convert` to ISO, GCZ, WIA, RVZ; `verify`; `header` | GameCube, Wii | GPL-2.0-or-later | Partly. Alpine community ships it inside `dolphin-emu`, which drags in Qt6, X11, FFmpeg and SDL3 (~54 MiB): too heavy. A headless source build (`-DENABLE_QT=OFF -DENABLE_NOGUI=ON`) should work; the exact minimal flags are *unverified*. Prints no progress while converting. nodtool covers the same formats with far less weight. |
| **wit** (Wiimms ISO Tools) | ISO, WBFS, WDF, CISO, WIA, GCZ (GCZ read-only); split WBFS for FAT32 | Wii, GameCube | GPL-2.0-or-later | Build from source (ncurses and fuse dev packages); the upstream binaries are glibc. v3.05a (2022), barely maintained. Its one unique strength is **split WBFS above 4 GB** for FAT32 USB loaders: the "split above 4 GB" option the mockup shows. |
| **NKit** (Nanook) | NKit ⇄ ISO/GCM; scan, verify, fix, dedupe. The v3 CLI also covers PS1/PS2/PS3, PSP, Dreamcast, Saturn, Xbox and Wii U | many | MIT | *Unverified on musl.* v3.0.1 (2026-09-27) ships native-AOT Linux CLI builds (x64 and arm64, plus legacy-glibc variants), but all are **glibc**. On Alpine it would need `gcompat`, or the .NET SDK to build a musl AOT binary. |
| **psx-vcd** (leji-a) | PS1 cue → VCD, merges split BINs itself | PS1 → PS2 (POPStarter) | MIT OR Apache-2.0 | Pure Rust, no C deps. Passed over for cue2pops: v0.1.1 with 5 commits, forces its own output name (serial prefix), writes a temporary copy of the whole image, and — read from its source — does not re-time single-BIN multi-track sheets, ignores `PREGAP`, and places multi-BIN tracks 2 s later than cue2pops, so CD audio may play off on POPStarter. |
| **PSXPackager** | PS1 BIN/CUE, m3u → PBP (single or multi-disc), and back | PS1 | MIT (+ DiscUtils) | Weak. v1.6.3 (2023) has a `linux-x64` CLI on .NET 6, which is end of life; v1.7 is GUI-only. Whether it is self-contained is *unverified*. |
| **popstationr** | BIN → PBP | PS1 | no licence file | Builds with make and zlib only, but the forks are unmaintained (pseiler, last push 2019). |
| **pop-fe** (sahlberg) | BIN/CUE → EBOOT.PBP, multi-disc, with covers | PS1 | LGPL-2.1 | Python, with a Dockerfile of its own; needs gcc, cmake and friends. Heavy for this image. |

## PS1 multi-disc: m3u + CHD per disc, or one PBP?

Conversion writes **one CHD per disc plus an `.m3u`** for a multi-disc set.
PBP is not built. The trade-offs:

**m3u + CHD** (what Conversion does)

- Lossless: CHD keeps CDDA audio and the Redump track layout.
- Read by DuckStation, Beetle PSX, SwanStation and PCSX-ReARMed. DuckStation's
  m3u accepts CUE or CHD entries.
- The m3u names the discs, so a frontend offers the disc swap and one memory
  card spans the set (Beetle PSX names the card after the m3u).
- Several files per game.

**One multi-disc PBP**

- A single file, and it is what a PSP, Vita or PS3 reads natively.
- Lossy in practice: CDDA and subchannel data survive only if the packer
  re-injects them.
- It cannot be encrypted for official firmware anyway.
- The tools are old and thinly maintained (above).
- libretro's Beetle PSX wants a multi-disc PBP as one file, *not* in an m3u.
- LibCrypt titles need an `.sbi` next to it, named `<name>_<n>.sbi` per disc.

**Real hardware** (xStation and PSIO optical drive emulators) reads neither CHD
nor PBP. xStation wants BIN/CUE; its CHD request is open as
[x-station/xstation-issues#198](https://github.com/x-station/xstation-issues/issues/198).
PSIO wants a single BIN with a CU2 and `MULTIDISC.LST`. The
`chd-to-cue` converter (`chdman extractcd`) is the way back to BIN/CUE for
them.

So PBP only earns its place for a PSP or Vita target. If that becomes a need,
PSXPackager is the most complete, but its .NET 6 runtime is the obstacle. It
would come in as its own converter (`m3u`/`cue` → `pbp`, console `psx`) with no
framework change.
