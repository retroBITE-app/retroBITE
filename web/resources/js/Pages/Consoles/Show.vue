<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import CreateDirectory from '@/Components/CreateDirectory.vue'
import FileUploader from '@/Components/FileUploader.vue'
import PageHeader from '@/Components/PageHeader.vue'
import BaseButton from '@/Components/UI/BaseButton.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import Toast from '@/Components/UI/Toast.vue'
import { useApiAction } from '@/Composables/useApiAction'
import { formatSize } from '@/Helpers/format'
import { route } from '@/routes'
import type { ConsoleMeta, FolderPill, Game, SelectOption } from '@/Types/api'

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
}>()

const query = ref('')
const scanning = ref(false)
const deleteOpen = ref(false)

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
 * Rescan the console directory and reload the page with what it found.
 */
function scan(): void {
  scanning.value = true

  router.post(
    route('console.scan', { console: props.console }),
    {},
    { onFinish: () => (scanning.value = false) },
  )
}

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
    <PageHeader>
      <template #title>
        <div class="flex items-center gap-4">
          <Link
            :href="route('consoles')"
            class="text-sm text-zinc-500 transition-colors hover:text-zinc-300"
          >
            ← Back
          </Link>
          <img :src="meta.icon" :alt="meta.name" class="h-8 w-8 object-contain opacity-80" />
          <h1 class="text-2xl font-semibold text-zinc-100">{{ meta.name }}</h1>
        </div>
      </template>

      <template #actions>
        <TextInput v-model="query" placeholder="Search titles, publisher, filename…" class="w-64" />
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
        <BaseButton :busy="scanning" busy-label="Scanning…" @click="scan"
          >Scan directory</BaseButton
        >
      </template>
    </PageHeader>

    <div class="mb-4 flex flex-wrap items-center gap-2">
      <button
        v-for="pill in folders"
        :key="pill.value || 'all'"
        type="button"
        :title="pill.value === '' ? 'Show all' : 'Click to select, Ctrl/Cmd+Click to toggle'"
        :class="
          isActive(pill.value)
            ? 'bg-zinc-700 text-zinc-100'
            : 'text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200'
        "
        class="cursor-pointer rounded px-3 py-1 text-xs font-medium transition-colors"
        @click="onPillClick(pill.value, $event)"
      >
        {{ pill.label }}
        <span class="ml-1 text-zinc-500">{{ pill.count }}</span>
      </button>

      <button
        v-if="canDeleteFolder"
        type="button"
        class="ml-auto cursor-pointer rounded px-3 py-1 text-xs font-medium text-red-400 transition-colors hover:bg-red-500/10"
        @click="openDelete"
      >
        Delete folder
      </button>
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
          <div
            :style="game.cover_url ? { height: coverHeight } : placeholderStyle"
            class="relative flex w-fit items-center justify-center overflow-hidden rounded-lg border-2 border-zinc-700 bg-zinc-800 transition-colors group-hover:border-emerald-500/60"
          >
            <img
              v-if="game.cover_url"
              :src="game.cover_url"
              :alt="game.title ?? game.file_name"
              class="block h-full w-auto"
              @load="onCoverLoad"
            />
            <img
              v-else
              :src="meta.file_icon"
              :alt="meta.name"
              class="h-16 w-16 object-contain opacity-30"
            />
          </div>

          <div class="w-full min-w-0 px-0.5">
            <p
              :title="game.title ?? game.file_name"
              class="truncate text-sm text-zinc-200 transition-colors group-hover:text-white"
            >
              {{ game.title ?? game.file_name }}
            </p>
            <div class="mt-1 flex items-center gap-2">
              <img
                v-if="game.region_meta?.icon"
                :src="game.region_meta.icon"
                :alt="game.region_meta.name"
                :title="game.region_meta.name"
                class="w-6 shrink-0 border border-zinc-700"
              />
              <span class="text-xs text-zinc-500">{{ formatSize(game.file_size) }}</span>
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
