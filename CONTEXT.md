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

**Provider** — ScreenScraper, the service consulted about what a file is. The
only outside party retroBite talks to.

**Match** — putting a game to the provider and recording the answer. A match is
asked once per game, never once per file.

**Merge** — folding one game into another on discovering they are the same
title. The surviving game keeps its identity; the other's files move across.

**Quota** — how much the provider will answer today. Two separate allowances,
and the one for questions that fail is much the smaller. This is why the system
prefers not to ask rather than ask and miss.

**Media** — artwork retroBite downloaded and owns: covers, screenshots, logos,
backdrops. The opposite of a game file in every way that matters — it did not
exist until the platform fetched it, and it can be deleted and fetched again
without anything being lost.

**Media type** — the provider's own name for a kind of artwork. Kept as the
provider writes it.

**Display role** — what a piece of artwork is *for* in the interface: a cover,
a logo, a backdrop. Several media types can fill one role, which is how a game
gets a cover whether the provider had a flat box or a rendered one.

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
