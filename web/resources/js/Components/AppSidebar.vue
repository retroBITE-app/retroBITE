<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import {
  PhGameController,
  PhList,
  PhSignOut,
  PhSlidersHorizontal,
  PhSquaresFour,
  PhX,
} from '@phosphor-icons/vue'
import AppBrand from '@/Components/AppBrand.vue'
import { useMediaQuery } from '@/Composables/useMediaQuery'
import { route } from '@/routes'

const NAV = [
  { href: route('dashboard'), label: 'Dashboard', icon: PhSquaresFour },
  { href: route('consoles'), label: 'Consoles', icon: PhGameController },
  { href: route('settings'), label: 'Settings', icon: PhSlidersHorizontal },
]

const page = usePage()

// Asked in the same units the stylesheet uses — Tailwind's `lg:` compiles to
// `(min-width: 64rem)`, so a px query would disagree with it wherever the
// browser's default font size is not 16px, and neither would match at 1023.5px.
const isColumn = useMediaQuery('(min-width: 64rem)')

const open = ref(false)
const toggleRef = ref<HTMLElement | null>(null)
const closeRef = ref<HTMLElement | null>(null)

const authUser = computed(() => page.props.auth?.user ?? null)
const consoles = computed(() => page.props.sidebar?.consoles ?? [])
const storage = computed(() => page.props.sidebar?.storage ?? null)

/** The current path alone — the console list page carries a `?folder=` query. */
const path = computed(() => page.url.split(/[?#]/)[0] ?? '')

/**
 * The installed console whose page we are on, if any. Compared as a whole path
 * segment against the known keys rather than by prefix, or `/consoles/gb` would
 * also claim `/consoles/gba` and `/consoles/gbc`.
 */
const activeConsole = computed(() => {
  const prefix = `${route('consoles')}/`

  if (!path.value.startsWith(prefix)) {
    return null
  }

  const key = decodeURIComponent(path.value.slice(prefix.length).split('/')[0] ?? '')

  return consoles.value.some((console) => console.key === key) ? key : null
})

/** Two characters for the avatar tile, matching the design's lowercase "rb". */
const initials = computed(() => (authUser.value ?? 'rb').slice(0, 2).toLowerCase())

// AppLayout is a persistent Inertia layout, so this component survives every
// visit — without closing on the URL, the drawer would stay open over the page
// it just navigated to. One watcher covers the nav, the console list, the logo
// and the browser's own back and forward.
watch(() => page.url, close)

// Nothing else in the app locks scrolling, and the drawer always closes on
// navigation, so there is no competing lock to coordinate with. The padding
// stands in for the scrollbar the lock removes — without it, every page shifts
// sideways by the scrollbar's width on open, on any browser that reserves one.
watch(open, (isOpen) => {
  const gutter = window.innerWidth - document.documentElement.clientWidth

  document.body.style.overflow = isOpen ? 'hidden' : ''
  document.body.style.paddingRight = isOpen && gutter > 0 ? `${gutter}px` : ''
})

onMounted(() => document.addEventListener('keydown', onKeydown))

onUnmounted(() => {
  document.removeEventListener('keydown', onKeydown)
  document.body.style.overflow = ''
  document.body.style.paddingRight = ''
})

function logout(): void {
  router.post(route('logout'))
}

/**
 * Open the drawer and move focus inside it, since the toggle goes inert behind
 * the panel that just covered it.
 */
async function openMenu(): Promise<void> {
  open.value = true
  await nextTick()
  closeRef.value?.focus()
}

/**
 * Close the drawer and hand focus back to the toggle — the closed drawer is
 * inert, so the browser would otherwise drop the focus ring to the body.
 *
 * Guarded, so the permanent column never steals focus on a navigation.
 */
async function close(): Promise<void> {
  if (!open.value) {
    return
  }

  open.value = false
  // The toggle is inert until this render lands, and focusing an inert element
  // is ignored — which is how focus ends up on the body instead.
  await nextTick()
  toggleRef.value?.focus()
}

/**
 * Escape closes the drawer, the way it closes any overlay.
 */
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    close()
  }
}

/**
 * Is this nav item the current page? The dashboard needs an exact match, since
 * every other path starts with its "/".
 *
 * Consoles yields to the installed list below it — on one console's page, that
 * console is what gets highlighted — and takes the mark back whenever no row
 * below claims the path.
 */
function isActive(href: string): boolean {
  if (href === route('consoles')) {
    return path.value.startsWith(href) && activeConsole.value === null
  }

  return href === route('dashboard') ? path.value === href : path.value.startsWith(href)
}
</script>

<template>
  <!--
    The design has no drawer — it drops to a bar of nav pills below its
    breakpoint, which left the console list, storage meter and user chip on
    desktop only. A drawer carries all of it and gives the page its height back.
  -->
  <button
    ref="toggleRef"
    type="button"
    aria-label="Open menu"
    aria-controls="app-sidebar"
    :aria-expanded="open"
    :inert="open"
    class="fixed top-4 left-4 z-30 grid h-10 w-10 cursor-pointer place-items-center rounded-lg border border-line-bright bg-surface/90 text-fg-soft backdrop-blur-sm transition-colors hover:bg-hover hover:text-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep lg:hidden"
    @click="openMenu"
  >
    <PhList :size="20" />
  </button>

  <!--
    Always mounted so it fades in step with the drawer's slide rather than
    popping. `pointer-events-none` when closed is the one class keeping the page
    clickable below `lg`.
  -->
  <div
    class="fixed inset-0 z-40 bg-scrim/72 transition-opacity duration-200 motion-reduce:transition-none lg:hidden"
    :class="open ? 'opacity-100' : 'pointer-events-none opacity-0'"
    @click="close"
  />

  <aside
    id="app-sidebar"
    :inert="!isColumn && !open"
    class="fixed inset-y-0 left-0 z-40 flex w-[272px] shrink-0 flex-col overflow-y-auto overscroll-contain border-r border-line bg-sunken transition-transform duration-200 motion-reduce:transition-none lg:sticky lg:bottom-auto lg:z-30 lg:h-screen lg:w-[244px] lg:translate-x-0"
    :class="open ? 'translate-x-0' : '-translate-x-full'"
  >
    <div class="flex items-center justify-between gap-3 px-5 pt-5 pb-4">
      <Link :href="route('dashboard')" aria-label="retroBITE home">
        <img src="/images/logo.webp" alt="retroBITE" class="block h-auto w-[132px]" />
      </Link>

      <button
        ref="closeRef"
        type="button"
        aria-label="Close menu"
        class="shrink-0 cursor-pointer rounded-md p-1.5 text-fg-muted transition-colors hover:bg-hover hover:text-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep lg:hidden"
        @click="close"
      >
        <PhX :size="18" />
      </button>
    </div>

    <nav class="flex flex-col gap-0.5 px-2.5" aria-label="Main">
      <Link
        v-for="item in NAV"
        :key="item.href"
        :href="item.href"
        class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-[13.5px] transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
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

    <template v-if="consoles.length">
      <p class="px-5 pt-5 pb-2 font-mono text-3xs tracking-[0.18em] text-fg-faint uppercase">
        Installed
      </p>
      <ul class="flex shrink-0 flex-col gap-px px-2.5">
        <li v-for="console in consoles" :key="console.key">
          <Link
            :href="route('console', { console: console.key })"
            class="flex items-center gap-2.5 rounded-[7px] px-2.5 py-1.5 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
            :class="
              activeConsole === console.key
                ? 'bg-accent-tint/13 text-accent shadow-[inset_2px_0_0_var(--color-accent-deep)]'
                : 'text-fg-cool hover:bg-hover hover:text-fg'
            "
            :aria-current="activeConsole === console.key ? 'page' : undefined"
          >
            <img
              :src="console.icon"
              :alt="console.name"
              class="h-[22px] w-[26px] shrink-0 object-contain"
            />
            <span class="flex-1 truncate text-[13px]">{{ console.name }}</span>
            <span
              class="font-mono text-2xs"
              :class="activeConsole === console.key ? 'text-accent-muted' : 'text-fg-faint'"
            >
              {{ console.game_count }}
            </span>
          </Link>
        </li>
      </ul>
    </template>

    <div class="mt-auto shrink-0 border-t border-line px-5 pt-4 pb-4.5">
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
