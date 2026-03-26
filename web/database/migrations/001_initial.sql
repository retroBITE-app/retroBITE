CREATE TABLE IF NOT EXISTS games (
    id           TEXT    PRIMARY KEY,
    console      TEXT    NOT NULL,
    file_name    TEXT    NOT NULL,
    file_path    TEXT    NOT NULL,
    file_size    INTEGER,
    title        TEXT,
    region       TEXT,
    cover_url    TEXT,
    first_seen_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
    last_seen_at  INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
);

CREATE TABLE IF NOT EXISTS settings (
    key        TEXT    PRIMARY KEY,
    value      TEXT    NOT NULL,
    updated_at INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
);

-- Web UI authentication — separate from the Samba/FTP USER/PASS env vars
CREATE TABLE IF NOT EXISTS web_users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    role          TEXT    NOT NULL DEFAULT 'viewer',
    created_at    INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
);
