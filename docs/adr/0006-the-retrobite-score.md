# 6. The retroBite score, from LaunchBox and RetroAchievements

Date: 2026-10-08

## Status

Accepted. Replaces ScreenScraper's `note` as `games.rating`. Amended
2026-10-12: popularity from three sources, and more ways to find a game in
the index (below).

## Context

`games.rating` was ScreenScraper's `note`, out of twenty, because it came in
every answer at no cost. It did not say whether a game is good. ScreenScraper's
users vote on the entry, and the vote follows its artwork and metadata. A
library sorted by it put well-documented games first, not good ones.

IGDB was looked at once and set aside. It needs a Twitch account and a key
for each install, and its scores for 80s and 90s games are thin. Every key an
operator has to register is another step at install, and another thing to
explain.

Two sources need no new account:

- **The LaunchBox Games Database** publishes itself as one public zip,
  rebuilt daily, with no key. Every game carries `CommunityRating` (out of
  five) and `CommunityRatingCount`. Most retro games have both: 2 480 of
  2 959 SNES games, 4 425 of 4 650 PlayStation games. The votes tell good
  games from bad ones. Super Mario World has 4.73 from 1 836 votes, Shaq Fu
  1.98 from 74.
- **RetroAchievements**, already synced for every identified set, says how
  many people play a game (`NumDistinctPlayers`) and how far they get
  (`NumAwarded` per achievement).

## Decision

`games.rating` holds our own score, out of a hundred
(`App\Support\RetroBiteScore`):

- **Three quarters is LaunchBox.** The rating is mixed with ten imaginary
  votes at the platform's average vote, so two perfect votes do not beat two
  thousand good ones.
- **A quarter is RetroAchievements.** It is 70 % players on a log scale
  (100 → 0, 100 000 → 100) and 30 % how far the typical player got (the
  median unlock count of the progression and win achievements, as a share of
  players). Its weight falls towards nothing below 500 players.
- A game LaunchBox does not rate keeps its three quarters at the platform's
  average, so popularity alone moves it only so far. A game neither source
  knows has no score.
- ScreenScraper's `note` is no longer stored. The migration clears the old
  values, so no ScreenScraper note sits beside a new score.

LaunchBox is kept locally, as the RetroAchievements hash index is:
`LaunchBoxIndex` downloads the zip weekly from the scheduler. It streams the
XML out of the zip with `XMLReader` and rebuilds `launchbox_games` and
`launchbox_names` in one transaction. The dump has no ScreenScraper or
RetroAchievements ids, so a game is found by title and platform: the title is
reduced by `LaunchBoxTitle::key()`, and alternate names are looked up too. On
the development library 90–99 % of identified games are found, depending on
the console.

Scoring reads only the database, so it runs on its own: after a match, after a
set sync and after the index is rebuilt. The menu items that fetched a rating
by hand are gone.

## Amendment, 2026-10-12: popularity from three sources

The first version scored a game from LaunchBox's rating alone whenever it had
no RetroAchievements set — 1 189 of the development library's identified
games. Nothing told a classic from a curiosity among them, and Metal Gear
Solid was where any well-rated game was. Two sources were already in the
database and unused for it:

- **LaunchBox's vote count**, as a percentile among every game LaunchBox
  lists on the platform: 702 votes is past the 99th on the PlayStation (216),
  and would be the whole Lynx library's worth.
- **ScreenScraper's `nbscrap`** of the game's most popular dump, of every
  dump the provider knows (`games.provider_scrapes`, kept by
  `ProviderDumps` from the answer that identified the game). Log scale,
  5 000 → 0 and 500 000 → 100. Noisy — a whole romset is scraped at once —
  so it counts half.

The score is now **seven tenths quality** (the LaunchBox rating as before)
and **three tenths popularity**: the weighted average of whichever of the
vote percentile, RetroAchievements (players and engagement, as before) and
the scrape count the game has, its share shrinking with how little there is
— a thin set or a scrape count alone is half as sure.

**For a rated game, popularity may stand at most 20 points from the quality**,
so it moves the score six points at most, either way. Measured without that
bound, Superman 64 rose from 17 to 30 and Bebe's Kids from 19 to 32: an
infamous game has the votes and the downloads because it is infamous. And a
fine game few have heard of had already been pulled towards the platform's
average for its few votes; low popularity counted that twice. With the bound,
the top of the development library is Ocarina of Time 96, Super Mario World
95, Super Mario 64, A Link to the Past and Metal Gear Solid 94; Superman 64
stays at the bottom.

A game LaunchBox does not rate still keeps its quality at the platform's
average, so popularity alone cannot make it great; a game with nothing but a
scrape count now has a score near the middle rather than none.

Finding a game in the index also got looser, still without guessing: the title
key ignores spacing, a possessive's apostrophe, "and" against "&" or "/" and
a Japanese long vowel; a LaunchBox name is also kept without its subtitle
where it is the only game on the platform with that main title; and a game is
looked up by its RetroAchievements set's title too, never a hack's,
prototype's, demo's or subset's. 5 906 of the development library's 6 226
identified games were found; 6 080 are.

## Consequences

- One more outside source, but it needs no account. The download is about
  100 MB a week, and a failed one leaves the previous index in place.
- Matching by title can miss, or match the wrong game. It is deliberately
  exact after normalising: a miss falls back on RetroAchievements and the
  platform average, and a wrong match would hand a game another game's score.
  Placeholders are never looked up, because their title is a filename.
- The score also orders the whole library: `games.library_rank`, the
  retroBite rank, is each scored game's place across every console. A moved
  score marks the ranks stale and the scheduler works them out every ten
  minutes (`retrobite:rank --stale`), since one statement rewrites every
  game's place and an identification run moves a score about once a second.
- The weights and anchors are constants in one class. Changing them and
  running `retrobite:score` rescores the library without fetching anything;
  the command queues it as a batch, `--now` runs it in place.
- Games identified before 2026-10-12 have no `provider_scrapes` until asked
  again: the migration marks them so, and the scheduler asks about 2 000 a
  night (`retrobite:dumps --queue --limit=2000`, 03:00) until all have been,
  scoring each again with what it learns. A new install never needs it: a
  game carries the count out of the match that identified it.
- The upgrade empties the LaunchBox index, whose keys were reduced the old
  way; the scheduler rebuilds an empty index within the hour. Nothing is
  scored while there is no index — a new install before its first sync
  included — so no game loses its score in the meantime, and the sync scores
  the library once it is in.
