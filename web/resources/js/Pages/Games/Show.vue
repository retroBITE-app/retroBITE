<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, type DefineComponent, h } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import IdentifyModal from '@/Components/IdentifyModal.vue'
import MoveModal from '@/Components/MoveModal.vue'
import PageHeader from '@/Components/PageHeader.vue'
import BaseButton from '@/Components/UI/BaseButton.vue'
import Toast from '@/Components/UI/Toast.vue'
import { useApiAction } from '@/Composables/useApiAction'
import { formatSize } from '@/Helpers/format'
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

const hasDetails = computed(() =>
  Boolean(props.game.developer || props.game.publisher || props.game.genre || props.game.players),
)

const badges = computed(() =>
  [
    props.meta.name,
    formatSize(props.game.file_size),
    extension.value,
    props.game.release_date,
    props.game.file_md5,
  ].filter((badge): badge is string => Boolean(badge)),
)

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
  <div class="flex flex-col gap-4">
    <div class="relative flex flex-col pb-10">
      <div
        class="absolute inset-0 min-h-125 bg-zinc-950 bg-cover bg-center"
        :style="game.backdrop_url ? { backgroundImage: `url(${game.backdrop_url})` } : {}"
      >
        <div v-if="game.backdrop_url" class="absolute inset-0 bg-black/60" />
        <div class="absolute inset-x-0 bottom-0 h-32 bg-linear-to-t from-zinc-950 to-transparent" />
      </div>

      <div class="z-10 p-8">
        <PageHeader>
          <template #title>
            <Link
              :href="route('console', { console: props.console })"
              class="text-sm text-white transition-colors hover:text-zinc-300"
            >
              ← Back
            </Link>
          </template>

          <template #actions>
            <div ref="actionsRef" class="relative">
              <BaseButton variant="secondary" @click="actionsOpen = !actionsOpen">
                Actions <span class="text-xs">▾</span>
              </BaseButton>

              <div
                v-show="actionsOpen"
                class="absolute right-0 z-50 mt-1 w-44 rounded border border-zinc-700 bg-zinc-800 shadow-lg"
              >
                <button
                  type="button"
                  class="flex w-full cursor-pointer items-center gap-2 px-3 py-2 text-sm text-zinc-200 transition-colors hover:bg-zinc-700"
                  @click="openAction('identify')"
                >
                  Identify
                </button>
                <button
                  v-if="folders.length > 1"
                  type="button"
                  class="flex w-full cursor-pointer items-center gap-2 px-3 py-2 text-sm text-zinc-200 transition-colors hover:bg-zinc-700"
                  @click="openAction('move')"
                >
                  Move
                </button>

                <div class="my-0.5 border-t border-zinc-700" />

                <button
                  type="button"
                  class="flex w-full cursor-pointer items-center gap-2 px-3 py-2 text-sm text-red-400 transition-colors hover:bg-zinc-700"
                  @click="openAction('delete')"
                >
                  Delete
                </button>
              </div>
            </div>
          </template>
        </PageHeader>

        <div class="flex gap-6 rounded-lg">
          <div class="min-h-88.75 w-64 shrink-0">
            <img
              v-if="game.cover_url"
              :src="game.cover_url"
              :alt="game.title ?? game.file_name"
              class="h-auto w-full rounded-md border border-zinc-600 object-contain"
            />
            <div
              v-else
              :style="{ aspectRatio: meta.cover_aspect ?? '5/7' }"
              class="flex w-full items-center justify-center rounded-md border border-zinc-600 bg-zinc-900"
            >
              <img
                :src="meta.file_icon"
                :alt="meta.name"
                class="h-34 w-34 object-contain opacity-30"
              />
            </div>
          </div>

          <div class="flex flex-col justify-start gap-3 py-4">
            <div class="flex items-center gap-3">
              <img
                v-if="game.logo_url"
                :src="game.logo_url"
                :alt="game.title ?? game.file_name"
                class="w-30"
              />
              <div>
                <p class="text-xl font-semibold text-zinc-100">
                  {{ game.title ?? game.file_name }}
                </p>
                <p class="mt-0.5 font-mono text-sm text-zinc-500">{{ game.file_name }}</p>
              </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
              <img
                v-if="game.region_meta?.icon"
                :src="game.region_meta.icon"
                :alt="game.region_meta.name"
                :title="game.region_meta.name"
                class="w-8 border-2 border-zinc-700"
              />
              <span
                v-for="badge in badges"
                :key="badge"
                class="inline-flex items-center rounded bg-zinc-700 px-2 pt-1 font-mono text-xs leading-none text-white"
              >
                {{ badge }}
              </span>
            </div>

            <dl
              v-if="hasDetails"
              class="grid max-w-xl grid-cols-[auto_1fr] gap-x-6 gap-y-1 text-sm"
            >
              <template v-if="game.developer">
                <dt class="self-center text-xs uppercase tracking-wider text-white">Developer</dt>
                <dd class="text-zinc-200">{{ game.developer }}</dd>
              </template>
              <template v-if="game.publisher">
                <dt class="self-center text-xs uppercase tracking-wider text-white">Publisher</dt>
                <dd class="text-zinc-200">{{ game.publisher }}</dd>
              </template>
              <template v-if="game.genre">
                <dt class="self-center text-xs uppercase tracking-wider text-white">Genre</dt>
                <dd class="text-zinc-200">{{ game.genre }}</dd>
              </template>
              <template v-if="game.players">
                <dt class="self-center text-xs uppercase tracking-wider text-white">Players</dt>
                <dd class="text-zinc-200">{{ game.players }}</dd>
              </template>
            </dl>

            <p class="max-w-3xl text-sm text-zinc-200">
              {{ game.description ?? 'No metadata yet — use Identify to fetch it.' }}
            </p>
          </div>
        </div>
      </div>
    </div>

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
