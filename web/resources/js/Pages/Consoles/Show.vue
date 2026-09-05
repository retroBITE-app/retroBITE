<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { PhArrowLeft, PhArrowsClockwise, PhMagnifyingGlass } from '@phosphor-icons/vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import CreateDirectory from '@/Components/CreateDirectory.vue'
import FileUploader from '@/Components/FileUploader.vue'
import BaseButton from '@/Components/UI/BaseButton.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import Toast from '@/Components/UI/Toast.vue'
import { useApiAction } from '@/Composables/useApiAction'
import { formatSize } from '@/Helpers/format'
import { route } from '@/routes'
import type { ConsoleMeta, FolderPill, Game, HashProgress, SelectOption } from '@/Types/api'

/** Fallbacks for a console that declares no cover geometry. */
const DEFAULT_COVER_HEIGHT = 280
const DEFAULT_COVER_ASPECT = '5/7'

const props = defineProps<{
  console: string
  meta: ConsoleMeta
  games: Game[]
  extensions: string[]
  folders: FolderPill[]
  folder: string
  upload_dirs: SelectOption[]
  pending_hash: number
}>()

const query = ref('')
const scanning = ref(false)
const deleteOpen = ref(false)
const pending = ref(props.pending_hash)
const hashing = ref(false)

let stopped = false

const hashBatch = useApiAction<HashProgress>(
  () => route('console.hash', { console: props.console }),
  { fallback: 'Hashing failed' },
)

const deleteFolder = useApiAction(
  () =>
    route('console.folder.destroy', {
      console: props.console,
      folder: singleSelection.value ?? '',
    }),
  { method: 'DELETE', fallback: 'Delete failed' },
)

const coverHeight = computed(() => `${props.meta.cover_height ?? DEFAULT_COVER_HEIGHT}px`)

const placeholderStyle = computed(() => {
  const height = props.meta.cover_height ?? DEFAULT_COVER_HEIGHT
  const [width, depth] = (props.meta.cover_aspect ?? DEFAULT_COVER_ASPECT).split('/').map(Number)
  const ratio = depth ? width / depth : 5 / 7

  return { height: `${height}px`, width: `${Math.round(height * ratio)}px` }
})

const filteredGames = computed(() => {
  const needle = query.value.trim().toLowerCase()

  if (!needle) {
    return props.games
  }

  return props.games.filter((game) =>
    [game.title, game.description, game.publisher, game.file_name].some((field) =>
      (field ?? '').toLowerCase().includes(needle),
    ),
  )
})

const selectedFolders = computed(
  () =>
    new Set(
      props.folder
        .split(',')
        .map((part) => part.trim())
        .filter(Boolean),
    ),
)

const singleSelection = computed(() =>
  selectedFolders.value.size === 1 ? [...selectedFolders.value][0] : null,
)

const canDeleteFolder = computed(
  () => singleSelection.value !== null && singleSelection.value !== 'root',
)

const currentFolderCount = computed(
  () => props.folders.find((pill) => pill.value === singleSelection.value)?.count ?? 0,
)

/** What this listing holds — the whole console until a folder pill narrows it. */
const listedBytes = computed(() =>
  props.games.reduce((total, game) => total + (game.file_size ?? 0), 0),
)

const unidentifiedCount = computed(
  () => props.games.filter((game) => !game.is_bios && game.identified_at === null).length,
)

/**
 * Is this filter pill part of the current selection? The "All" pill is active
 * only when nothing else is.
 */
function isActive(value: string): boolean {
  return value === '' ? selectedFolders.value.size === 0 : selectedFolders.value.has(value)
}

/**
 * Visit the page with a folder selection.
 */
function navigate(folders: string[]): void {
  router.get(
    route('console', { console: props.console }),
    { folder: folders.join(',') },
    { preserveScroll: true },
  )
}

/**
 * Plain click replaces the selection; Ctrl/Cmd-click toggles one pill.
 */
function onPillClick(value: string, event: MouseEvent): void {
  if (value === '') {
    navigate([])

    return
  }

  if (!(event.ctrlKey || event.metaKey)) {
    navigate([value])

    return
  }

  const next = new Set(selectedFolders.value)

  if (next.has(value)) {
    next.delete(value)
  } else {
    next.add(value)
  }

  navigate([...next])
}

/**
 * Rescan the console directory and reload the page with what it found. Scanning
 * only indexes; the hashing the library still needs runs afterwards.
 */
function scan(): void {
  scanning.value = true

  router.post(
    route('console.scan', { console: props.console }),
    {},
    {
      onFinish: () => {
        scanning.value = false
        void hashBacklog()
      },
    },
  )
}

/**
 * Digest the console's unhashed files, one time-boxed batch per request.
 *
 * Hashing a shelf of ISOs runs far longer than a request may, so the server
 * returns after a budget and reports what is left; this repeats until the
 * backlog is empty, a batch makes no progress, or the page goes away.
 */
async function hashBacklog(): Promise<void> {
  if (hashing.value) {
    return
  }

  hashing.value = true

  try {
    for (;;) {
      const batch = await hashBatch.run()

      if (stopped || batch === null || batch.hashed === 0) {
        break
      }

      pending.value = batch.remaining

      if (batch.remaining === 0) {
        // The rows now carry an md5, so the listing can show what matched.
        router.reload()

        break
      }
    }
  } finally {
    hashing.value = false
  }
}

// Inertia reuses this component across its own visits, so the backlog is read
// from the fresh props rather than only from the first mount.
watch(
  () => props.pending_hash,
  (count) => {
    pending.value = count

    if (count > 0) {
      void hashBacklog()
    }
  },
)

// Picked up on load rather than only after a scan, so an upload or an
// interrupted run finishes on its own the next time the page is open.
onMounted(() => {
  if (pending.value > 0) {
    void hashBacklog()
  }
})

onUnmounted(() => {
  stopped = true
})

/**
 * Open the delete confirmation, but only for a deletable selection.
 */
function openDelete(): void {
  if (canDeleteFolder.value) {
    deleteFolder.reset()
    deleteOpen.value = true
  }
}

/**
 * Delete the selected folder, then reload without a folder filter since the one
 * that was selected no longer exists.
 */
async function confirmDeleteFolder(): Promise<void> {
  if (!canDeleteFolder.value) {
    return
  }

  if (await deleteFolder.run()) {
    deleteOpen.value = false
    router.get(route('console', { console: props.console }), { folder: '' })
  }
}

/**
 * Match the link's width to the cover that just loaded, so the caption aligns
 * with a cover of any aspect ratio.
 */
function onCoverLoad(event: Event): void {
  const image = event.target as HTMLImageElement
  const link = image.closest('a')

  if (link) {
    link.style.width = `${image.offsetWidth}px`
  }
}
</script>

<template>
  <div>
    <div class="mb-5 flex flex-wrap items-center gap-x-4 gap-y-3">
      <Link
        :href="route('consoles')"
        class="flex items-center gap-1.5 text-[13px] text-fg-muted transition-colors hover:text-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
      >
        <PhArrowLeft :size="15" />
        Consoles
      </Link>

      <span aria-hidden="true" class="h-[22px] w-px bg-line-strong" />

      <img :src="meta.icon" :alt="meta.name" class="h-8.5 w-8.5 shrink-0 object-contain" />
      <h1 class="text-[21px] font-medium text-fg-bright">{{ meta.name }}</h1>
      <p class="font-mono text-2xs text-fg-faint">
        {{ meta.path }} · {{ formatSize(listedBytes) }}
      </p>

      <div class="ml-auto flex flex-wrap items-center gap-2">
        <!-- The toolbar's own field shape: no visible label, so IconField (which
             always renders one) does not fit. -->
        <div
          class="flex w-[230px] items-center gap-2 rounded-lg border border-line-strong bg-surface px-2.75 py-1.75 transition-colors focus-within:border-accent-deep"
        >
          <PhMagnifyingGlass :size="14" class="shrink-0 text-fg-faint" />
          <TextInput
            v-model="query"
            bare
            aria-label="Search this console"
            placeholder="Title, publisher, filename"
          />
        </div>

        <CreateDirectory
          :console-key="props.console"
          :console-name="meta.name"
          :folder="meta.folder"
          @done="router.reload()"
        />
        <FileUploader
          :console-key="props.console"
          :console-name="meta.name"
          :accepted-extensions="extensions"
          :upload-dirs="upload_dirs"
          @done="router.reload()"
        />
        <BaseButton
          class="flex items-center gap-1.5"
          :busy="scanning"
          busy-label="Scanning…"
          @click="scan"
        >
          <PhArrowsClockwise :size="14" />
          Scan
        </BaseButton>
      </div>
    </div>

    <div class="mb-5 flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-raised pb-3.5">
      <div class="flex flex-wrap items-center gap-1.5">
        <button
          v-for="pill in folders"
          :key="pill.value || 'all'"
          type="button"
          :title="pill.value === '' ? 'Show all' : 'Click to select, Ctrl/Cmd+Click to toggle'"
          :class="
            isActive(pill.value) ? 'bg-line-strong text-fg-bright' : 'text-fg-muted hover:text-fg'
          "
          class="cursor-pointer rounded-[7px] px-2.75 py-1.25 text-xs transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
          @click="onPillClick(pill.value, $event)"
        >
          {{ pill.label }}
          <span class="ml-1.5 font-mono text-2xs opacity-80">{{ pill.count }}</span>
        </button>
      </div>

      <div class="ml-auto flex items-center gap-3.5">
        <span
          v-if="hashing"
          class="flex items-center gap-2 font-mono text-2xs text-accent uppercase"
        >
          <span
            class="h-3 w-3 shrink-0 animate-spin rounded-full border-2 border-line-bright border-t-accent"
            aria-hidden="true"
          />
          Hashing · {{ pending }} left
        </span>
        <span v-if="unidentifiedCount" class="font-mono text-2xs text-fg-faint uppercase">
          {{ unidentifiedCount }} unidentified
        </span>
        <button
          v-if="canDeleteFolder"
          type="button"
          class="cursor-pointer rounded-[7px] px-2.5 py-1 text-xs text-danger transition-colors hover:bg-danger/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
          @click="openDelete"
        >
          Delete folder
        </button>
      </div>
    </div>

    <EmptyState
      v-if="games.length === 0"
      message="No files found."
      hint="Try scanning the directory, or upload something."
    />

    <EmptyState v-else-if="filteredGames.length === 0" :message="`No games match “${query}”.`" />

    <ul v-else class="flex flex-wrap gap-4">
      <li v-for="game in filteredGames" :key="game.file_name" class="group">
        <Link
          :href="route('game', { console: props.console, game: game.file_name })"
          class="flex flex-col gap-2"
        >
          <!-- Height and placeholder ratio come from the console's own config: a
               SNES box is wide and flat where a PS2 case is tall. -->
          <div
            :style="game.cover_url ? { height: coverHeight } : placeholderStyle"
            class="relative flex w-fit items-center justify-center overflow-hidden rounded-[10px] border border-line-strong bg-sunken transition-colors group-hover:border-accent-tint/50"
          >
            <img
              v-if="game.cover_url"
              :src="game.cover_url"
              :alt="game.title ?? game.file_name"
              class="block h-full w-auto"
              @load="onCoverLoad"
            />
            <div
              v-else
              class="flex h-full w-full flex-col items-center justify-center gap-3 bg-[linear-gradient(165deg,var(--color-raised),var(--color-sunken))]"
            >
              <img
                :src="meta.file_icon"
                :alt="meta.name"
                class="h-14 w-14 object-contain opacity-25"
              />
              <span
                v-if="!game.is_bios"
                class="font-mono text-3xs tracking-[0.08em] text-fg-faint uppercase"
              >
                Unidentified
              </span>
            </div>

            <span
              v-if="game.is_bios"
              class="absolute top-2 right-2 rounded-[5px] border border-line-input bg-scrim/80 px-1.5 py-0.5 font-mono text-3xs text-fg-muted"
            >
              BIOS
            </span>
          </div>

          <div class="w-full min-w-0 px-0.5">
            <p
              :title="game.title ?? game.file_name"
              class="truncate text-[13px] text-fg transition-colors group-hover:text-fg-bright"
            >
              {{ game.title ?? game.file_name }}
            </p>
            <div class="mt-1 flex items-center gap-2">
              <img
                v-if="game.region_meta?.icon"
                :src="game.region_meta.icon"
                :alt="game.region_meta.name"
                :title="game.region_meta.name"
                class="w-6 shrink-0 border border-line-input"
              />
              <span class="font-mono text-2xs text-fg-dim">{{ formatSize(game.file_size) }}</span>
            </div>
          </div>
        </Link>
      </li>
    </ul>

    <ConfirmDialog
      :open="deleteOpen"
      :title="`Delete folder ${singleSelection ?? ''}?`"
      :message="`This will permanently delete ${meta.folder}/${singleSelection ?? ''} from disk and remove ${currentFolderCount} game record${currentFolderCount === 1 ? '' : 's'} from the database.`"
      warning="The folder, every file inside it, and all nested subfolders will be removed. This cannot be undone."
      confirm-label="Delete folder"
      variant="danger"
      :busy="deleteFolder.busy.value"
      @cancel="deleteOpen = false"
      @confirm="confirmDeleteFolder"
    />

    <Toast v-if="deleteFolder.error.value">{{ deleteFolder.error.value }}</Toast>
  </div>
</template>
