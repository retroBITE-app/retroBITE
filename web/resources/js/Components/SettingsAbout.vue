<script setup lang="ts">
import type { Component } from 'vue'
import {
  PhArrowUpRight,
  PhBookOpen,
  PhBug,
  PhCode,
  PhDatabase,
  PhGameController,
  PhGithubLogo,
  PhHardDrives,
  PhImages,
  PhPaintBrush,
  PhShapes,
  PhTextAa,
} from '@phosphor-icons/vue'
import type { AboutData } from '@/Types/api'

/** Icon names config/about.php may use, mapped so only these ship in the bundle. */
const ICONS: Record<string, Component> = {
  book: PhBookOpen,
  brush: PhPaintBrush,
  bug: PhBug,
  code: PhCode,
  database: PhDatabase,
  drives: PhHardDrives,
  gamepad: PhGameController,
  github: PhGithubLogo,
  images: PhImages,
  shapes: PhShapes,
  type: PhTextAa,
}

const props = defineProps<{ about: AboutData }>()

/**
 * The component for an icon name, falling back to a neutral glyph so an unknown
 * name still renders a row.
 */
function icon(name: string): Component {
  return ICONS[name] ?? PhCode
}

/**
 * A link's host and path, without the scheme the design does not show.
 */
function display(url: string): string {
  return url.replace(/^https?:\/\//, '')
}
</script>

<template>
  <div>
    <div class="grid items-start gap-5.5 lg:grid-cols-[1.35fr_1fr]">
      <div class="rounded-xl border border-line bg-sunken px-5.5 py-5">
        <div class="flex items-center gap-2.5">
          <PhHardDrives :size="18" class="text-accent" />
          <p class="text-lg font-medium text-fg-bright">{{ props.about.name }}</p>
        </div>
        <p
          v-for="(paragraph, index) in props.about.summary"
          :key="index"
          class="mt-3 max-w-[78ch] text-sm leading-relaxed text-fg-soft text-pretty"
        >
          {{ paragraph }}
        </p>
      </div>

      <div class="overflow-hidden rounded-xl border border-line bg-sunken">
        <div class="border-b border-line px-4.5 py-4">
          <p class="kicker text-fg-faint">Version</p>
          <p class="mt-1.5 font-mono text-xl text-fg-bright">{{ props.about.version }}</p>
        </div>

        <dl>
          <div
            v-for="row in props.about.build"
            :key="row.key"
            class="flex justify-between gap-3 border-b border-line/70 px-4.5 py-2.5 text-sm"
          >
            <dt class="text-fg-dim">{{ row.key }}</dt>
            <dd class="truncate font-mono text-fg-soft">{{ row.value }}</dd>
          </div>
        </dl>

        <div class="flex flex-col gap-2 px-4.5 py-4">
          <a
            v-for="link in props.about.links"
            :key="link.url"
            :href="link.url"
            target="_blank"
            rel="noopener noreferrer"
            class="flex items-center gap-2.5 rounded-lg border border-line-strong px-2.5 py-2.5 transition-colors hover:border-accent-tint/50 hover:bg-accent-tint/6 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
          >
            <component :is="icon(link.icon)" :size="16" class="shrink-0 text-accent" />
            <span class="min-w-0 flex-1">
              <span class="block text-sm text-fg-soft">{{ link.label }}</span>
              <span class="mt-0.5 block truncate font-mono text-xs text-fg-dim">
                {{ display(link.url) }}
              </span>
            </span>
            <PhArrowUpRight :size="13" class="shrink-0 text-fg-faint" />
          </a>
        </div>
      </div>
    </div>

    <div class="mt-7.5">
      <h2 class="text-lg font-medium text-fg-bright">Credits</h2>
      <p class="mt-1.5 mb-4 text-sm text-fg-dim">
        Libraries, data and files used to make {{ props.about.name }} work.
      </p>

      <ul class="grid gap-2.5 md:grid-cols-2">
        <li
          v-for="credit in props.about.credits"
          :key="credit.name"
          class="rounded-xl border border-line bg-sunken px-3.5 py-3"
        >
          <div class="flex items-center gap-2.5">
            <component :is="icon(credit.icon)" :size="15" class="shrink-0 text-fg-muted" />
            <p class="min-w-0 truncate text-sm text-fg-soft">{{ credit.name }}</p>
          </div>
          <p class="mt-2 text-xs leading-normal text-fg-dim text-pretty">{{ credit.role }}</p>
        </li>
      </ul>
    </div>
  </div>
</template>
