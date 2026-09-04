<script setup lang="ts">
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { PhArrowRight, PhImage } from '@phosphor-icons/vue'
import { formatRelative, formatSize } from '@/Helpers/format'
import { route } from '@/routes'
import type { DashboardGame } from '@/Types/api'

const props = defineProps<{ game: DashboardGame }>()

const page = usePage()

const scanlines = computed(() => page.props.ui.scanlines)

/** The path as the library holds it — the real filesystem path is never sent. */
const libraryPath = computed(() => `${props.game.console_folder}/${props.game.file_name}`)

const href = computed(() =>
  route('game', { console: props.game.console, game: props.game.file_name }),
)
</script>

<template>
  <div
    class="relative flex h-[300px] overflow-hidden rounded-xl border border-line-strong bg-sunken"
  >
    <div
      v-if="props.game.backdrop_url"
      class="absolute inset-0 bg-cover bg-[position:50%_20%]"
      :style="{ backgroundImage: `url(${props.game.backdrop_url})` }"
    />
    <PhImage
      v-else
      :size="40"
      aria-hidden="true"
      class="absolute top-1/2 right-8 -translate-y-1/2 text-line-bright"
    />

    <!-- Reads left-to-right, so the text side is darkened hardest. -->
    <div
      class="pointer-events-none absolute inset-0 bg-[linear-gradient(90deg,rgb(10_10_11/0.94)_0%,rgb(10_10_11/0.72)_46%,rgb(10_10_11/0.15)_100%)]"
    />
    <div v-if="scanlines" class="scanlines absolute inset-0" />

    <div class="relative mt-auto w-full p-6">
      <p class="font-mono text-3xs tracking-[0.2em] text-accent uppercase">
        Recently added · {{ props.game.console_name }}
      </p>

      <h2 class="mt-2 truncate text-[28px] font-medium tracking-[-0.01em] text-fg-bright">
        {{ props.game.title ?? props.game.file_name }}
      </h2>

      <p class="mt-1 truncate font-mono text-xs text-fg-muted">
        {{ libraryPath }} · {{ formatSize(props.game.file_size) }} ·
        {{ formatRelative(props.game.first_seen_at) }}
      </p>

      <div class="mt-4">
        <Link
          :href="href"
          class="inline-flex items-center gap-1.5 rounded-lg border border-accent-tint/60 px-3.5 py-2 text-[13px] text-accent transition-colors hover:bg-accent-tint/14 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
        >
          View details
          <PhArrowRight :size="13" />
        </Link>
      </div>
    </div>
  </div>
</template>
