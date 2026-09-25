# Context

The vocabulary retroBite is built on. Several of these words are narrower or
wider than they first look, and the differences are the reason the system
behaves the way it does. This file is a glossary and nothing else — how any of
it is built belongs in the code.

## The library

**Library** — everything retroBite knows about. Not a thing you can point at:
there is no library object, only the games and files that make one up, and a
view that presents them together.

**Console** — a system retroBite can hold games for. Editable configuration
rather than a fixed list, so adding one is a matter of describing it. A console
knows its name, which file extensions it plays, and how the metadata provider
refers to it.

A console is **installed** when a folder for it exists. Nothing else makes it
so; there is no separate act of installation.

## Games and their files

**Game** — one title in the library. The unit a person thinks in: *Final
Fantasy IX* is one game whether it arrived as one file or nine.

A game's identity is the provider's own id for it. That id is stable across
every region, revision and disc of the same title, which is what lets two
dumps of one game recognise each other as one game.

**Placeholder** — a game that has been found but not yet identified. It exists
from the moment a file is seen, so the library fills up on screen while
identification runs behind it. Its title is a guess taken from the filename.

**Identified** — the provider recognised the game and its metadata is real.

**Unmatched** — the provider was asked properly and does not know this game.
Distinct from a placeholder: the question has been put and answered. Also
distinct from a failure to ask, which leaves a game a placeholder.

**Game file** — a file on disk that belongs to a game. Deliberately wider than
"ROM": a cuesheet, a data track and a disc playlist are none of them ROMs, but
all belong to the game and all should be visible in the library.

A game file records facts — where it is, how big it is, which disc it holds.
It never records whether the game has been identified. That is said once, about
the game.

**Missing** — a game file that was there and no longer is. It is not deleted,
because a file that has gone is nearly always a disk that is not mounted rather
than one somebody threw away.

## The shapes a game takes on disk

**Rom** — a complete game image. Identifiable on its own.

**Sheet** — a cuesheet: a few lines of text naming the tracks of one disc.

**Track** — a data or audio file named by a sheet. Not a game, but the part of
a disc the provider actually recognises.

**Playlist** — a file naming the discs of a multi-disc game in order. The one
place on disk that says "these files are one game".

**Disc** — one physical volume of a game that shipped on several. Discs are
numbered; how many there are in total is not something the provider can be
trusted about.

## Metadata and artwork

**Provider** — an outside service consulted about a game. There are two, and
they answer different questions. ScreenScraper says what a file *is*;
RetroAchievements says what there is to *do* in it. Neither depends on the
other, and a game one of them has never heard of can be perfectly well known to
the other. Unqualified, the word means ScreenScraper, which is the older of the
two and the one a game's identity comes from.

**Match** — putting a game to the provider and recording the answer. A match is
asked once per game, never once per file.

**Merge** — folding one game into another on discovering they are the same
title. The surviving game keeps its identity; the other's files move across.

**Quota** — how much the provider will answer today. Two separate allowances,
and the one for questions that fail is much the smaller. This is why the system
prefers not to ask rather than ask and miss.

**Rating** — ScreenScraper's own mark for a game, voted on by their users.
Not a critic's score and not ours. It rides along in every answer, so it costs
nothing to have, but it only exists for games the provider knows, and a game
with no rating is commoner than one with. Held out of a hundred because that is
how the library reads it; the provider counts to twenty.

**Media** — artwork retroBite downloaded and owns: covers, screenshots, logos,
backdrops. The opposite of a game file in every way that matters — it did not
exist until the platform fetched it, and it can be deleted and fetched again
without anything being lost.

**Thumbnail** — a smaller copy of a cover, made from the downloaded original
for the interface to show instead of it: one for the list view, one for the
shelf. Not media in its own right — it has no row, belongs to the cover it was
made from, and goes when that does. The original is kept; the game page's
viewer shows it in full.

**Media list** — the artwork the provider *offers* for a game, as its last
answer listed it. Not media: nothing in it has been downloaded. Every jeuInfos
answer carries it, so identifying a game, reading its rating and learning its
artwork are one request, and the list is kept (`media_lists`) so choosing
artwork again later costs none. Renewed only by "re-fetch all", or by any
other answer that happens to arrive.

**Media type** — the provider's own name for a kind of artwork. Kept as the
provider writes it. One slot per type *and* region: a game may hold the same
cover from four regions on purpose, but never two European ones, so fetching
again replaces a revised copy without touching the other regions.

**Media region** — which region's artwork a game shows. Distinct from the
game's `region`, which is the ROM's own: a Japanese import can be the copy
somebody owns while the English box is the one they want to look at. Null is
the ordinary state and means "whatever Settings says", so changing the
library-wide preference still moves every game that has not been spoken for.

**Display role** — what a piece of artwork is *for* in the interface: a cover,
a logo, a backdrop. Several media types can fill one role, which is how a game
gets a cover whether the provider had a flat box or a rendered one.

## Achievements

**Achievement set** — everything RetroAchievements defines for a title. Not a
game in retroBite's sense: one set answers for every region and every disc of a
title, it belongs to RetroAchievements rather than to us, and it can be deleted
and fetched again without anything being lost. A game points at a set; the set
does not belong to the game.

**Achievement** — one goal inside a set. Worth points, and pictured by a badge.

**Unlock** — that a person has an achievement. Said about a person and an
achievement, never about a game. An unlock outlives the achievement leaving the
set: the set is theirs to change, the unlock is a fact about somebody's evening.

**Hardcore** — a mode in the emulator where save states and rewind are off. A
property of an *unlock*, not of a person and not of a game: the same game can be
forty out of forty softcore and three out of forty hardcore at the same time,
which is why it is two dates on one row and never a flag.

**Award** — RetroAchievements' own verdict on how far somebody got in a game:
beaten, completed, mastered. Beaten is recorded separately for the two modes.
Always taken from them and never worked out here, because it is their judgement
and not a sum.

**Hash index** — RetroAchievements' own table of which file hashes belong to
which set, fetched a console at a time and ahead of need. The reason
identifying a game costs no request. Being absent from it is never final: sets
are added constantly, and a game that was in no set in January is identified the
night its set appears.

**RA hash** — how RetroAchievements recognises a file. Not a checksum of the
file: what is hashed depends on the console, with headers stripped on some and,
for a disc, the game's own executable hashed instead of the disc. Computed by
their tool rather than by us, and remembered against the file's size and
modification time so a disc image is never read twice for it.

## Grouping

**Game collection** — a list of games somebody put together by hand. Not a
series and not an export target: a franchise is metadata, and what goes onto a
particular device is a different idea again.

## Deliberately not concepts

**Export format** — how files must be laid out for a particular loader. Real,
and not part of the library: a console does not determine it, and nothing here
models it yet.

**Administrator** — there is no such role. Everyone who can log in can do
everything.
