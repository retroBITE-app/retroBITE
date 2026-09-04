CREATE TABLE IF NOT EXISTS login_attempts (
    ip           TEXT    PRIMARY KEY,
    attempts     INTEGER NOT NULL DEFAULT 0,
    window_start INTEGER NOT NULL DEFAULT (strftime('%s','now')),
    locked_until INTEGER
);
