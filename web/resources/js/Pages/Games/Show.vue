<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, type DefineComponent, h } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import {
  PhArrowLeft,
  PhCaretDown,
  PhCopy,
  PhDotsThree,
  PhFileArchive,
  PhFolderOpen,
  PhMagicWand,
  PhTrash,
} from '@phosphor-icons/vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import IdentifyModal from '@/Components/IdentifyModal.vue'
import MoveModal from '@/Components/MoveModal.vue'
import CopyButton from '@/Components/UI/CopyButton.vue'
import Toast from '@/Components/UI/Toast.vue'
import { useApiAction } from '@/Composables/useApiAction'
import { formatRelative, formatSize } from '@/Helpers/format'
import AppLayout from '@/Layouts/AppLayout.vue'
import { route } from '@/routes'
import type { ConsoleMeta, Game, SelectOption } from '@/Types/api'

// This page paints its own backdrop to the edges, so it opts out of the layout's
// padding rather than using the default layout.
defineOptions({
  layout: (_render: typeof h, page: DefineComponent) =>
    h(AppLayout, { noPadding: true }, () => page),
})

const props = defineProps<{
  console: string
  meta: ConsoleMeta
  game: Game
  folder: string
  folder_label: string
  folders: SelectOption[]
}>()

const page = usePage()

const scanlines = computed(() => page.props.ui.scanlines)

const actionsOpen = ref(false)
const actionsRef = ref<HTMLElement | null>(null)
const identifyOpen = ref(false)
const moveOpen = ref(false)
const deleteOpen = ref(false)

const destroy = useApiAction(
  () => route('game.destroy', { console: props.console, game: props.game.file_name }),
  { method: 'DELETE', fallback: 'Delete failed' },
)

// Computed, not a plain const: IdentifyModal reloads the `game` prop in place.
const extension = computed(() => props.game.file_name.split('.').pop()?.toUpperCase() ?? '—')

/** Menu entries that open a modal. Copy and Delete are rendered separately. */
const ACTIONS = computed(() =>
  [
    { kind: 'identify' as const, label: 'Identify game', icon: PhMagicWand },
    { kind: 'move' as const, label: 'Move to folder', icon: PhFolderOpen, needsFolders: true },
  ].filter((action) => !action.needsFolders || props.folders.length > 1),
)

const chips = computed(() =>
  [props.meta.name, props.game.release_date].filter((chip): chip is string => Boolean(chip)),
)

/** The design's amber eyebrow: the console, then the year if one is known. */
const kicker = computed(() =>
  [props.meta.name, props.game.release_date?.slice(0, 4)].filter(Boolean).join(' · '),
)

/** Path as the library holds it — the real filesystem path is never sent. */
const libraryPath = computed(() => `${props.meta.path}/${props.game.file_name}`)

const detailRows = computed(() =>
  [
    { key: 'Developer', value: props.game.developer },
    { key: 'Publisher', value: props.game.publisher },
    { key: 'Genre', value: props.game.genre },
    { key: 'Players', value: props.game.players },
  ].filter((row): row is { key: string; value: string } => Boolean(row.value)),
)

const fileRows = computed(() => [
  { key: 'Size', value: formatSize(props.game.file_size) },
  { key: 'Format', value: extension.value },
  { key: 'Added', value: formatRelative(props.game.first_seen_at) },
  { key: 'Last seen', value: formatRelative(props.game.last_seen_at) },
])

onMounted(() => document.addEventListener('click', onClickOutside))
onUnmounted(() => document.removeEventListener('click', onClickOutside))

/**
 * Open one of the action modals, closing the dropdown that launched it.
 */
function openAction(which: 'identify' | 'move' | 'delete'): void {
  actionsOpen.value = false
  destroy.reset()

  identifyOpen.value = which === 'identify'
  moveOpen.value = which === 'move'
  deleteOpen.value = which === 'delete'
}

/**
 * Delete the file and return to the console listing, where it no longer appears.
 */
async function confirmDelete(): Promise<void> {
  if (await destroy.run()) {
    deleteOpen.value = false
    router.visit(route('console', { console: props.console }))
  }
}

/**
 * Dismiss the actions dropdown on a click anywhere outside it.
 */
function onClickOutside(event: MouseEvent): void {
  if (actionsRef.value && !actionsRef.value.contains(event.target as Node)) {
    actionsOpen.value = false
  }
}
</script>

<template>
  <div class="pb-14">
    <!-- Hero: the backdrop runs to the edges and the detail block is pulled up
         over its lower half, so the poster and title sit on the art. -->
    <div class="relative h-[300px] lg:h-[560px]">
      <div
        v-if="game.backdrop_url"
        class="absolute inset-0 bg-cover bg-[position:50%_28%]"
        :style="{ backgroundImage: `url(${game.backdrop_url})` }"
      />
      <div
        class="absolute inset-0 bg-[linear-gradient(180deg,rgb(12_12_13/0.55)_0%,rgb(12_12_13/0.6)_45%,var(--color-ground)_100%)]"
      />
      <div v-if="game.backdrop_url && scanlines" class="scanlines absolute inset-0" />

      <!-- pl-14 clears the floating hamburger, which sits at top-4 left-4. -->
      <div
        class="absolute top-4 right-4 left-4 flex items-center gap-3.5 pl-14 lg:top-[22px] lg:right-[30px] lg:left-[30px] lg:pl-0"
      >
        <Link
          :href="route('console', { console: props.console })"
          class="flex items-center gap-1.5 rounded-lg border border-line-input bg-scrim/60 px-2.75 py-1.5 text-[13px] text-fg-soft backdrop-blur-sm transition-colors hover:border-line-bright focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
        >
          <PhArrowLeft :size="14" />
          {{ meta.name }}
        </Link>

        <div ref="actionsRef" class="relative ml-auto">
          <button
            type="button"
            :aria-expanded="actionsOpen"
            aria-haspopup="menu"
            class="flex cursor-pointer items-center gap-1.75 rounded-lg border border-line-input bg-scrim/60 px-3 py-1.75 text-[13px] text-fg-soft backdrop-blur-sm transition-colors hover:border-line-bright focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
            @click="actionsOpen = !actionsOpen"
          >
            <PhDotsThree :size="14" />
            Actions
            <PhCaretDown :size="11" class="text-fg-dim" />
          </button>

          <div
            v-show="actionsOpen"
            role="menu"
            class="absolute top-10 right-0 z-20 w-[214px] rounded-[10px] border border-line-input bg-surface p-1.25 shadow-2xl"
          >
            <button
              v-for="action in ACTIONS"
              :key="action.kind"
              type="button"
              role="menuitem"
              class="flex w-full cursor-pointer items-center gap-2.5 rounded-[7px] px-2.5 py-2 text-[13px] text-fg-soft transition-colors hover:bg-raised focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
              @click="openAction(action.kind)"
            >
              <component :is="action.icon" :size="15" class="text-fg-muted" />
              {{ action.label }}
            </button>

            <div class="my-1.25 mx-2 h-px bg-line" />

            <CopyButton :text="libraryPath" label="Copy path" variant="menu">
              <template #icon><PhCopy :size="15" class="text-fg-muted" /></template>
            </CopyButton>

            <button
              type="button"
              role="menuitem"
              class="flex w-full cursor-pointer items-center gap-2.5 rounded-[7px] px-2.5 py-2 text-[13px] text-danger transition-colors hover:bg-danger/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
              @click="openAction('delete')"
            >
              <PhTrash :size="15" />
              Delete file
            </button>
          </div>
        </div>
      </div>
    </div>

    <div
      class="relative -mt-[190px] grid items-start gap-[18px] px-4 lg:-mt-[450px] lg:grid-cols-[208px_minmax(0,1fr)] lg:gap-[26px] lg:px-8"
    >
      <!-- The cover keeps its own proportions: object-contain never crops, and
           the placeholder takes its shape from the console's config. -->
      <div class="w-[148px] lg:w-full">
        <img
          v-if="game.cover_url"
          :src="game.cover_url"
          :alt="game.title ?? game.file_name"
          class="h-auto w-full rounded-[10px] border border-line-input object-contain shadow-[0_18px_40px_rgb(0_0_0/0.55)]"
        />
        <div
          v-else
          :style="{ aspectRatio: meta.cover_aspect ?? '5/7' }"
          class="flex w-full items-center justify-center rounded-[10px] border border-line-input bg-sunken shadow-[0_18px_40px_rgb(0_0_0/0.55)]"
        >
          <img :src="meta.file_icon" :alt="meta.name" class="h-20 w-20 object-contain opacity-25" />
        </div>
      </div>

      <div class="min-w-0">
        <p class="font-mono text-3xs tracking-[0.2em] text-accent uppercase">{{ kicker }}</p>

        <div class="mt-2 flex flex-wrap items-end gap-x-3.5 gap-y-1">
          <img
            v-if="game.logo_url"
            :src="game.logo_url"
            :alt="game.title ?? game.file_name"
            class="h-auto w-28 shrink-0"
          />
          <h1 class="text-2xl font-medium tracking-[-0.015em] text-fg-bright lg:text-[34px]">
            {{ game.title ?? game.file_name }}
          </h1>
        </div>

        <p class="mt-1.5 font-mono text-xs break-all text-fg-soft">{{ game.file_name }}</p>

        <div class="mt-4 flex flex-wrap items-center gap-1.75">
          <!-- 26.5px is exactly the badges' height beside it: a 16.5px line box,
               their 8px of padding and 2px of border. Stated outright because no
               spacing step lands on it. The width follows, since region flags
               are not all one shape. -->
          <img
            v-if="game.region_meta?.icon"
            :src="game.region_meta.icon"
            :alt="game.region_meta.name"
            :title="game.region_meta.name"
            class="h-[26.5px] w-auto shrink-0 border-2 border-line-input"
          />
          <span
            v-for="chip in chips"
            :key="chip"
            class="rounded-[5px] border border-line-strong bg-surface px-2 py-1 font-mono text-2xs text-fg-muted"
          >
            {{ chip }}
          </span>
        </div>

        <dl
          v-if="detailRows.length"
          class="mt-5 grid w-fit max-w-full grid-cols-[repeat(2,max-content)] gap-x-8.5 gap-y-2 lg:grid-cols-[repeat(4,max-content)]"
        >
          <div v-for="row in detailRows" :key="row.key">
            <dt class="font-mono text-3xs tracking-[0.14em] text-fg-dim uppercase">
              {{ row.key }}
            </dt>
            <dd class="mt-1.25 text-[13px] text-fg-bright">{{ row.value }}</dd>
          </div>
        </dl>

        <p class="mt-5 max-w-[100ch] text-[13.5px] leading-[1.65] text-fg-muted text-pretty">
          {{ game.description ?? 'No metadata yet — use Identify to fetch it.' }}
        </p>
      </div>
    </div>

    <section class="relative z-1 px-4 pt-6.5 lg:px-8 lg:pt-10">
      <div class="overflow-hidden rounded-xl border border-line bg-sunken">
        <div
          class="flex flex-wrap items-center gap-x-3.5 gap-y-2 border-b border-raised px-4.5 py-3.75"
        >
          <PhFileArchive :size="17" class="text-fg-muted" />
          <h2 class="text-base font-medium text-fg-bright">File</h2>
          <!-- No copy control here: the Actions menu already offers Copy path. -->
          <span class="ml-auto font-mono text-2xs break-all text-fg-dim">{{ libraryPath }}</span>
        </div>

        <dl class="grid grid-cols-2 border-b border-raised lg:grid-cols-4">
          <div
            v-for="(row, index) in fileRows"
            :key="row.key"
            class="border-raised px-4.5 py-3.5"
            :class="[
              index % 2 === 0 ? 'border-r' : '',
              index < 2 ? 'border-b lg:border-b-0' : '',
              'lg:border-r lg:last:border-r-0',
            ]"
          >
            <dt class="font-mono text-3xs tracking-[0.14em] text-fg-faint uppercase">
              {{ row.key }}
            </dt>
            <dd class="mt-1.75 font-mono text-sm text-fg-bright">{{ row.value }}</dd>
          </div>
        </dl>

        <div class="px-4.5 py-3.5">
          <div class="flex items-center gap-2">
            <span class="font-mono text-3xs tracking-[0.14em] text-fg-faint uppercase">MD5</span>
            <CopyButton v-if="game.file_md5" :text="game.file_md5" />
          </div>
          <p class="mt-1.75 font-mono text-xs break-all text-fg-soft">
            {{ game.file_md5 ?? 'Not hashed yet — scan the directory to compute it.' }}
          </p>
        </div>
      </div>
    </section>

    <IdentifyModal
      :open="identifyOpen"
      :console-key="props.console"
      :game-file-name="game.file_name"
      @close="identifyOpen = false"
    />

    <MoveModal
      :open="moveOpen"
      :console-key="props.console"
      :game-file-name="game.file_name"
      :folder="folder"
      :folder-label="folder_label"
      :folders="folders"
      @close="moveOpen = false"
    />

    <ConfirmDialog
      :open="deleteOpen"
      title="Delete this file?"
      :message="`“${game.file_name}” will be permanently removed from storage, along with its metadata.`"
      warning="This cannot be undone. The file will be lost forever if you proceed."
      confirm-label="Delete forever"
      variant="danger"
      :busy="destroy.busy.value"
      @confirm="confirmDelete"
      @cancel="deleteOpen = false"
    />

    <Toast v-if="destroy.error.value">{{ destroy.error.value }}</Toast>
  </div>
</template>
