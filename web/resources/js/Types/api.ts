/**
 * Payload shapes the PHP side sends. One definition per shape — these were
 * previously re-declared per consumer and had drifted apart.
 *
 * Keys are snake_case because that is the JSON contract; see CLAUDE.md.
 */

/** A `{value, label}` pair for a picker. */
export type SelectOption = {
  value: string
  label: string
}

/** A folder filter pill, as built by GameRepository::folderCounts(). */
export type FolderPill = SelectOption & {
  count: number
}

/** Region metadata, from App\Support\Region::toArray(). */
export type RegionMeta = {
  key: string
  name: string
  icon: string
  codes: string[]
}

/** Console presentation fields, from Console::toMetaArray(). */
export type ConsoleMeta = {
  name: string
  icon: string
  file_icon: string
  folder: string
  cover_aspect: string | null
  cover_height: number | null
}

/** A console card, from Console::toCardArray(). */
export type ConsoleCard = {
  key: string
  name: string
  icon: string
  game_count: number
  bios_count: number
}

/** A network share row, from Console::toShareArray(). */
export type Share = {
  key: string
  name: string
  folder: string
  icon: string | null
}

/** The dashboard's network block. */
export type NetworkInfo = {
  host_ip: string
  username: string
  shares: Share[]
}

/** A game plus its provider metadata, from GameDataService::enrichGame(). */
export type Game = {
  file_name: string
  file_size: number | null
  file_md5: string | null
  title: string | null
  description: string | null
  cover_url: string | null
  logo_url: string | null
  backdrop_url: string | null
  release_date: string | null
  genre: string | null
  players: string | null
  publisher: string | null
  developer: string | null
  region: string | null
  region_meta: RegionMeta | null
  first_seen_at: number | null
  last_seen_at: number | null
  identified_at: number | null
}

/** A candidate match returned by the metadata provider. */
export type ProviderCandidate = {
  provider_id: string
  title: string | null
  rom_name: string | null
  region: string | null
  year: string | null
  cover_url: string | null
  release_date?: string | null
  genre?: string | null
  players?: string | null
  publisher?: string | null
  developer?: string | null
}

/** Field types an overridable setting may declare — mirrors App\Enums\SettingFieldType. */
export type SettingFieldType = 'text' | 'url' | 'number' | 'text[]'

/** One field's schema entry, from config/overridable.php. */
export type FieldMeta = {
  type: SettingFieldType
  label?: string
  required?: boolean
}

/** One settings tab. */
export type SettingsGroup = {
  slug: string
  label: string
  schema: Record<string, FieldMeta>
  items: Record<string, Record<string, unknown>>
  overrides: string[]
}

/** The error shape every failing endpoint returns. */
export type ApiError = {
  error: string
  code?: string
  errors?: Record<string, string>
}
