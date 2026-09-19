# 1. Achievement progress belongs to the set, not to the game

Date: 2026-09-19

## Status

Accepted

## Context

RetroAchievements data needs an owner. The obvious one is `games`: the library
is a list of games, a game is what a person opens, and `screenscraper_id`
already lives there — so `retroachievements_id`, the achievements and a person's
unlocks could all hang off `games.id` and every query would be one join shorter.

Three things argue against it, and the first is not hypothetical.

`GameMatcher::apply()` has a merge branch. When ScreenScraper answers with a
provider id another game already holds, the two are the same title — a second
region, a second dump — and the branch moves the files across and **deletes**
the losing row. `media` is already `cascadeOnDelete`, which is correct, because
artwork can be fetched again. Unlocks cannot. Putting them on `games.id` means
that discovering two copies of a game are the same game silently destroys a
person's record of playing it, at a moment nobody is watching.

Second, a set is not a game in our sense. One set answers for every region and
every disc of a title, so two library games can legitimately point at one set.

Third, the data is not ours. Achievement sets can be deleted and fetched again
without loss; unlocks and progress cannot. Keeping them apart from `games` makes
that difference visible in the schema rather than only in someone's head.

## Decision

`ra_games` owns the achievement data, keyed by RetroAchievements' own game id as
its primary key. `games.retroachievements_id` is a pointer into it and nothing
more. Achievements hang off `ra_games`, unlocks off achievements, and progress
off `(user_id, ra_game_id)`.

## Consequences

Progress survives a merge, a deleted placeholder, a rebuilt library and a
changed `GAMES_PATH`. It also survives the local game being deleted outright,
which is the right answer: unlocking something is a thing that happened, and
removing a file from a disk does not un-happen it.

The merge branch still has to hand `retroachievements_id` to the survivor when
it has none, or the identification is paid for twice. That is a single copy of
three columns inside the existing transaction.

The cost is one level of indirection. The library list would have joined
`games → ra_progress` either way, so it is unaffected; the game page resolves
the set before reading its achievements. `achievements_possible` is denormalised
onto `ra_progress` specifically so the list needs no second join for the "31 /
49" on each card.

Progress rows can outlive every game that pointed at them — a set nobody owns a
copy of any more. That is deliberate, and the reason `ra_games` rows are never
deleted by the index sync even when RetroAchievements stops listing a hash.
