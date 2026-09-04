CREATE TABLE IF NOT EXISTS game_metadata (
    md5           TEXT    PRIMARY KEY,
    provider      TEXT    NOT NULL DEFAULT 'screenscraper',
    provider_id   TEXT,
    title         TEXT,
    description   TEXT,
    cover_url     TEXT,
    logo_url      TEXT,
    backdrop_url  TEXT,
    release_date  TEXT,
    genre         TEXT,
    players       TEXT,
    publisher     TEXT,
    developer     TEXT,
    raw           TEXT,
    fetched_at    INTEGER NOT NULL DEFAULT (strftime('%s','now'))
);

CREATE INDEX IF NOT EXISTS idx_game_metadata_provider_id ON game_metadata(provider, provider_id);
