<?php

use Illuminate\Support\Facades\Broadcast;

// Anyone who can log in can do anything (see CLAUDE.md), so both channels ask
// for a login and nothing more. Private rather than public all the same: the
// container listens on the LAN, and the signals name games.
Broadcast::channel('system', fn ($user): bool => $user !== null);

Broadcast::channel('games.{id}', fn ($user, int $id): bool => $user !== null);
