<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, usePage, router } from '@inertiajs/vue3'
import AppBrand from '@/Components/AppBrand.vue'
import { route } from '@/routes'

const page = usePage()
const open = ref(false)

const NAV = [
  { href: route('dashboard'), label: 'Dashboard' },
  { href: route('consoles'), label: 'Consoles' },
  { href: route('settings'), label: 'Settings' },
]

const authUser = computed(() => page.props.auth?.user ?? null)

/**
 * Sign out and land on the login page.
 */
function logout(): void {
  router.post(route('logout'))
}

/**
 * Is this nav item the current page? The dashboard needs an exact match, since
 * every other path starts with its "/".
 */
function isActive(href: string): boolean {
  return href === route('dashboard') ? page.url === href : page.url.startsWith(href)
}
</script>

<template>
  <button
    class="fixed top-4 left-4 z-50 cursor-pointer flex lg:hidden items-center justify-center w-10 h-10 rounded-md bg-zinc-800 text-zinc-300 hover:text-zinc-100 hover:bg-zinc-700 transition-colors"
    aria-label="Toggle menu"
    @click="open = !open"
  >
    <!-- Hamburger icon -->
    <svg
      v-if="!open"
      xmlns="http://www.w3.org/2000/svg"
      class="w-5 h-5"
      fill="none"
      viewBox="0 0 24 24"
      stroke="currentColor"
      stroke-width="2"
    >
      <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
    </svg>
    <!-- Close icon -->
    <svg
      v-else
      xmlns="http://www.w3.org/2000/svg"
      class="w-5 h-5"
      fill="none"
      viewBox="0 0 24 24"
      stroke="currentColor"
      stroke-width="2"
    >
      <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
    </svg>
  </button>

  <!-- Backdrop (mobile only) -->
  <div v-if="open" class="fixed inset-0 z-30 bg-black/50 lg:hidden" @click="open = false" />

  <!-- Sidebar -->
  <aside
    class="fixed inset-y-0 left-0 z-40 w-60 flex flex-col bg-zinc-900 border-r border-zinc-800 transition-transform duration-200 lg:relative lg:translate-x-0 lg:shrink-0"
    :class="open ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
  >
    <!-- Logo -->
    <div class="px-5 py-6 border-b border-zinc-800">
      <img src="/logo.png" alt="retroBITE" class="h-20 mx-auto w-auto" />
    </div>

    <!-- Nav -->
    <nav class="flex-1 px-3 py-4 space-y-0.5">
      <Link
        v-for="item in NAV"
        :key="item.href"
        :href="item.href"
        class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium transition-colors"
        :class="
          isActive(item.href)
            ? 'bg-emerald-500/10 text-emerald-400'
            : 'text-zinc-400 hover:text-zinc-100 hover:bg-zinc-800'
        "
        @click="open = false"
      >
        {{ item.label }}
      </Link>
    </nav>

    <!-- Footer -->
    <div class="px-4 py-4 border-t border-zinc-800 flex items-center gap-3">
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-zinc-300 truncate">{{ authUser }}</p>
        <AppBrand class="text-zinc-600" />
      </div>
      <button
        title="Sign out"
        class="shrink-0 cursor-pointer flex items-center justify-center w-8 h-8 rounded-md text-zinc-500 hover:text-red-400 hover:bg-zinc-800 transition-colors"
        @click="logout"
      >
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75"
          />
        </svg>
      </button>
    </div>
  </aside>
</template>
