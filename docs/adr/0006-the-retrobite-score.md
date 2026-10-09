# 6. The retroBite score, from LaunchBox and RetroAchievements

Date: 2026-10-08

## Status

Accepted. Replaces ScreenScraper's `note` as `games.rating`.

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

## Consequences

- One more outside source, but it needs no account. The download is about
  100 MB a week, and a failed one leaves the previous index in place.
- Matching by title can miss, or match the wrong game. It is deliberately
  exact after normalising: a miss falls back on RetroAchievements and the
  platform average, and a wrong match would hand a game another game's score.
  Placeholders are never looked up, because their title is a filename.
- The score also orders the whole library: `games.library_rank`, the
  retroBite rank, is each scored game's place across every console, worked out
  again whenever a score moves.
- The weights and anchors are constants in one class. Changing them and
  running `retrobite:score` rescores the library without fetching anything.
