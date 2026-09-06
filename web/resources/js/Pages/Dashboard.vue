<script setup lang="ts">
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { PhImage, PhMagicWand } from '@phosphor-icons/vue'
import DashboardHero from '@/Components/DashboardHero.vue'
import NetworkPanel from '@/Components/NetworkPanel.vue'
import PageHeader from '@/Components/PageHeader.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import { formatRelative, formatSize } from '@/Helpers/format'
import { route } from '@/routes'
import type { DashboardGame, DashboardStat, NetworkInfo, UnmatchedGames } from '@/Types/api'

/** Hour the greeting changes, each the start of its period. Morning owns the rest. */
const GREETINGS = [
  [17, 'Good evening'],
  [12, 'Good afternoon'],
] as const

const props = defineProps<{
  hero: DashboardGame | null
  recent: DashboardGame[]
  stats: DashboardStat[]
  unmatched: UnmatchedGames
  network: NetworkInfo
}>()

const page = usePage()

const scanlines = computed(() => page.props.ui.scanlines)

/** The layout already carries the volume figures, so the cell reuses them. */
const storage = computed(() => page.props.sidebar?.storage ?? null)

const cells = computed<DashboardStat[]>(() => [
  ...props.stats,
  {
    label: 'Storage',
    value: storage.value?.used ?? '—',
    sub: storage.value?.total ? `of ${storage.value.total}` : 'on disk',
  },
])

// The viewer's clock, not the server's — a library reachable from another room
// is still "this evening" to whoever is looking at it.
const greeting = computed(() => {
  const hour = new Date().getHours()

  return GREETINGS.find(([from]) => hour >= from)?.[1] ?? 'Good morning'
})

/**
 * Link to a game's own page.
 */
function gameHref(game: DashboardGame): string {
  return route('game', { console: game.console, game: game.file_name })
}
</script>

<template>
  <div class="flex flex-col gap-8">
    <PageHeader :kicker="greeting">
      <template #title>
        <h1 class="text-display font-medium tracking-display text-fg-bright">Your collection</h1>
      </template>
    </PageHeader>

    <EmptyState
      v-if="!props.hero"
      message="Nothing in the library yet."
      hint="Install a console, upload some files, then scan it."
    />

    <div v-else class="grid gap-4 lg:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)]">
      <DashboardHero :game="props.hero" />

      <div class="flex min-w-0 flex-col gap-2.5">
        <Link
          v-for="game in props.recent"
          :key="game.file_name"
          :href="gameHref(game)"
          class="relative flex min-w-0 flex-1 items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface p-2.5 transition-colors hover:border-line-input hover:bg-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
        >
          <!-- The game's own key art, faded out before it reaches the text. The
               mask covers the scanlines too, so they stop where the art does. -->
          <span
            v-if="game.backdrop_url"
            aria-hidden="true"
            class="art-fade-l pointer-events-none absolute inset-0"
          >
            <span
              class="absolute inset-0 bg-cover bg-center"
              :style="{ backgroundImage: `url(${game.backdrop_url})` }"
            />
            <span v-if="scanlines" class="scanlines absolute inset-0" />
          </span>

          <img
            v-if="game.cover_url"
            :src="game.cover_url"
            alt=""
            class="relative h-18 w-13 shrink-0 rounded-md border border-line-input object-cover"
          />
          <span
            v-else
            aria-hidden="true"
            class="relative grid h-18 w-13 shrink-0 place-items-center rounded-md border border-line-input bg-sunken"
          >
            <PhImage :size="18" class="text-fg-faint" />
          </span>

          <span class="relative min-w-0 flex-1">
            <span class="block truncate text-sm text-fg">
              {{ game.title ?? game.file_name }}
            </span>
            <span class="mt-1 block text-sm text-fg-dim">
              {{ game.console_name }} · added {{ formatRelative(game.first_seen_at) }}
            </span>
            <span class="mt-1 block font-mono text-xs text-fg-faint">
              {{ formatSize(game.file_size) }}
            </span>
          </span>
        </Link>
      </div>
    </div>

    <section>
      <dl
        class="grid grid-cols-2 overflow-hidden rounded-xl border border-line bg-sunken lg:grid-cols-4"
      >
        <div
          v-for="(cell, index) in cells"
          :key="cell.label"
          class="border-line px-4.5 py-4"
          :class="[
            index % 2 === 0 ? 'border-r' : '',
            index < 2 ? 'border-b lg:border-b-0' : '',
            'lg:border-r lg:last:border-r-0',
          ]"
        >
          <dt class="kicker text-fg-faint">
            {{ cell.label }}
          </dt>
          <dd class="mt-2 text-[22px] font-medium tracking-display text-fg-bright">
            {{ cell.value }}
          </dd>
          <dd class="mt-1 text-sm text-fg-dim">{{ cell.sub }}</dd>
        </div>
      </dl>
    </section>

    <section v-if="props.unmatched.rows.length">
      <div class="mb-3.5 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
        <h2 class="text-lg font-medium text-fg-bright">Needs identifying</h2>
        <p class="text-sm text-fg-dim">
          {{ props.unmatched.total }}
          {{ props.unmatched.total === 1 ? 'file' : 'files' }} scanned but not matched to a title
        </p>
      </div>

      <ul class="overflow-hidden rounded-xl border border-line bg-sunken">
        <li
          v-for="game in props.unmatched.rows"
          :key="`${game.console}/${game.file_name}`"
          class="flex flex-wrap items-center gap-x-5 gap-y-3 border-b border-line/70 px-4 py-3 transition-colors last:border-b-0 hover:bg-hover"
        >
          <span class="min-w-0 flex-1">
            <span class="block truncate font-mono text-sm text-fg-soft">{{ game.file_name }}</span>
            <span class="mt-1.5 flex items-center gap-2.5">
              <span
                class="rounded border border-line-strong px-1.5 py-0.5 kicker text-accent-muted"
              >
                {{ game.console }}
              </span>
              <span class="font-mono text-xs text-fg-faint">
                {{ formatSize(game.file_size) }} · {{ formatRelative(game.first_seen_at) }}
              </span>
            </span>
          </span>

          <Link
            :href="gameHref(game)"
            class="flex shrink-0 items-center gap-1.5 rounded-lg border border-accent-tint/50 px-2.5 py-1.5 text-sm text-accent transition-colors hover:bg-accent-tint/12 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
          >
            <PhMagicWand :size="13" />
            Identify
          </Link>
        </li>
      </ul>
    </section>

    <section>
      <NetworkPanel :network="props.network" />
    </section>
  </div>
</template>
