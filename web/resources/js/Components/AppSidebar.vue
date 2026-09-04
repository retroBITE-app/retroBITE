<script setup lang="ts">
import { computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import {
  PhGameController,
  PhSignOut,
  PhSlidersHorizontal,
  PhSquaresFour,
} from '@phosphor-icons/vue'
import AppBrand from '@/Components/AppBrand.vue'
import { route } from '@/routes'

const NAV = [
  { href: route('dashboard'), label: 'Dashboard', icon: PhSquaresFour },
  { href: route('consoles'), label: 'Consoles', icon: PhGameController },
  { href: route('settings'), label: 'Settings', icon: PhSlidersHorizontal },
]

const page = usePage()

const authUser = computed(() => page.props.auth?.user ?? null)
const consoles = computed(() => page.props.sidebar?.consoles ?? [])
const storage = computed(() => page.props.sidebar?.storage ?? null)

/** Two characters for the avatar tile, matching the design's lowercase "rb". */
const initials = computed(() => (authUser.value ?? 'rb').slice(0, 2).toLowerCase())

function logout(): void {
  router.post(route('logout'))
}

/**
 * Is this nav item the current page? The dashboard needs an exact match, since
 * every other path starts with its "/". Prefix matching keeps Consoles lit while
 * viewing one console or one game.
 */
function isActive(href: string): boolean {
  return href === route('dashboard') ? page.url === href : page.url.startsWith(href)
}
</script>

<template>
  <aside
    class="sticky top-0 z-30 flex w-full shrink-0 flex-col border-b border-line bg-sunken lg:h-screen lg:w-[244px] lg:overflow-y-auto lg:border-r lg:border-b-0"
  >
    <div class="flex items-center justify-between px-4 pt-3 pb-2.5 lg:px-5 lg:pt-5 lg:pb-4">
      <Link :href="route('dashboard')" aria-label="retroBITE home">
        <img src="/images/logo.webp" alt="retroBITE" class="block h-auto w-[132px]" />
      </Link>

      <!--
        The design has no logout below its breakpoint, which would strand a
        signed-in user on a phone. This is the mobile-only stand-in; the desktop
        one lives in the user chip.
      -->
      <button
        type="button"
        aria-label="Sign out"
        class="shrink-0 cursor-pointer rounded-md p-1.5 text-fg-muted transition-colors hover:bg-hover hover:text-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep lg:hidden"
        @click="logout"
      >
        <PhSignOut :size="18" />
      </button>
    </div>

    <nav
      class="flex gap-1 overflow-x-auto px-3 pb-2.5 lg:flex-col lg:gap-0.5 lg:overflow-x-visible lg:px-2.5 lg:pb-0"
      aria-label="Main"
    >
      <Link
        v-for="item in NAV"
        :key="item.href"
        :href="item.href"
        class="flex shrink-0 items-center gap-2.5 rounded-lg px-3 py-2 text-[13.5px] whitespace-nowrap transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
        :class="
          isActive(item.href)
            ? 'bg-accent-tint/13 text-accent shadow-[inset_2px_0_0_var(--color-accent-deep)]'
            : 'text-fg-cool hover:bg-hover hover:text-fg'
        "
        :aria-current="isActive(item.href) ? 'page' : undefined"
      >
        <component :is="item.icon" :size="17" />
        {{ item.label }}
      </Link>
    </nav>

    <!-- Everything below is desktop-only, as drawn. -->
    <template v-if="consoles.length">
      <p
        class="hidden px-5 pt-5 pb-2 font-mono text-3xs tracking-[0.18em] text-fg-faint uppercase lg:block"
      >
        Installed
      </p>
      <ul class="hidden shrink-0 flex-col gap-px px-2.5 lg:flex">
        <li v-for="console in consoles" :key="console.key">
          <Link
            :href="route('console', { console: console.key })"
            class="flex items-center gap-2.5 rounded-[7px] px-2.5 py-1.5 text-fg-cool transition-colors hover:bg-hover hover:text-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
          >
            <img
              :src="console.icon"
              :alt="console.name"
              class="h-[22px] w-[26px] shrink-0 object-contain"
            />
            <span class="flex-1 truncate text-[13px]">{{ console.name }}</span>
            <span class="font-mono text-2xs text-fg-faint">{{ console.game_count }}</span>
          </Link>
        </li>
      </ul>
    </template>

    <div class="mt-auto hidden shrink-0 border-t border-line px-5 pt-4 pb-4.5 lg:block">
      <template v-if="storage">
        <div
          class="flex justify-between font-mono text-3xs tracking-[0.08em] text-fg-muted uppercase"
        >
          <span>Storage</span>
          <span>{{ storage.total ? `${storage.used} / ${storage.total}` : storage.used }}</span>
        </div>
        <!-- No bar without a capacity to divide by; the figure still stands. -->
        <div
          v-if="storage.percent !== null"
          class="mt-2 mb-4 h-1 overflow-hidden rounded-sm bg-raised"
        >
          <div
            class="h-full rounded-sm bg-accent-deep transition-[width] duration-300"
            :style="{ width: `${storage.percent}%` }"
          />
        </div>
        <div v-else class="mb-4" />
      </template>

      <div class="flex items-center gap-2.5">
        <span
          aria-hidden="true"
          class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md border border-line-input bg-raised font-mono text-2xs text-fg-muted"
        >
          {{ initials }}
        </span>
        <div class="min-w-0 flex-1 leading-tight">
          <p class="truncate text-xs text-fg-soft">{{ authUser }}</p>
          <AppBrand class="text-fg-faint" />
        </div>
        <button
          type="button"
          aria-label="Sign out"
          class="shrink-0 cursor-pointer rounded-md p-1 text-fg-muted transition-colors hover:bg-hover hover:text-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
          @click="logout"
        >
          <PhSignOut :size="16" />
        </button>
      </div>
    </div>
  </aside>
</template>
