CREATE TABLE IF NOT EXISTS games (
    id           TEXT    PRIMARY KEY,
    console      TEXT    NOT NULL,
    file_name    TEXT    NOT NULL,
    file_path    TEXT    NOT NULL,
    file_size    INTEGER,
    title        TEXT,
    region       TEXT,
    first_seen_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    last_seen_at  INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
);

CREATE TABLE IF NOT EXISTS settings (
    key        TEXT    PRIMARY KEY,
    value      TEXT    NOT NULL,
    updated_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
);