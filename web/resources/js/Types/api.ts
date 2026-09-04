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
  /** Folder relative to the library root, e.g. "games/snes". */
  path: string
  cover_aspect: string | null
  cover_height: number | null
}

/** A console that has no folder yet, offered by the New console dialog. */
export type InstallableConsole = {
  key: string
  name: string
}

/** A console card, from Console::toCardArray(). */
export type ConsoleCard = {
  key: string
  name: string
  icon: string
  /** Folder relative to the library root, e.g. "games/gc". */
  path: string
  game_count: number
  bios_count: number
  identified_count: number
  /** Bytes occupied, BIOS images included. */
  bytes: number
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
  /** True for a file sitting in the console's BIOS folder. */
  is_bios: boolean
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
export type SettingFieldType = 'text' | 'url' | 'number' | 'text[]' | 'bool'

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

/** The library volume's fill, from HostSummaryService::storageMeter(). */
export type StorageMeter = {
  used: string
  /** Null when the volume's capacity cannot be read. */
  total: string | null
  /** Null alongside a null total — there is no denominator to divide by. */
  percent: number | null
}

/** What the layout's sidebar renders, shared with every authenticated page. */
export type SidebarData = {
  consoles: ConsoleCard[]
  storage: StorageMeter
}

/** One row of the About tab's build table. */
export type BuildRow = {
  key: string
  value: string
}

/** An outbound link on the About tab. */
export type AboutLink = {
  icon: string
  label: string
  url: string
}

/** One acknowledgement on the About tab. */
export type CreditItem = {
  icon: string
  name: string
  role: string
}

/** The About tab's content, from AboutService::payload(). */
export type AboutData = {
  name: string
  version: string
  summary: string[]
  build: BuildRow[]
  links: AboutLink[]
  credits: CreditItem[]
}

/** Project identity, shared with every page. */
export type AppIdentity = {
  name: string
  version: string
  repo_url: string
}

/** A game as the dashboard renders it: the enriched payload plus its console. */
export type DashboardGame = Game & {
  console: string
  console_name: string
  console_folder: string
}

/** One cell of the dashboard's figure grid. */
export type DashboardStat = {
  label: string
  value: string
  sub: string
}

/** The dashboard's "needs identifying" block. */
export type UnmatchedGames = {
  rows: DashboardGame[]
  total: number
}

/** Presentation preferences, shared with every page. */
export type UiPreferences = {
  scanlines: boolean
}

/** One figure in the login page's "on this host" block. */
export type HostStat = {
  value: string
  label: string
}

/** The error shape every failing endpoint returns. */
export type ApiError = {
  error: string
  code?: string
  errors?: Record<string, string>
}
