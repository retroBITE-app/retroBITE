DROP TABLE IF EXISTS settings;

CREATE TABLE settings (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    "group"    TEXT    NOT NULL,
    "key"      TEXT    NOT NULL,
    value      TEXT    NOT NULL,
    updated_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),
    UNIQUE ("group", "key")
);

CREATE INDEX IF NOT EXISTS idx_settings_group ON settings("group");
