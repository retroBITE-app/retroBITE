<script setup lang="ts">
import { ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

const page = usePage()
const open = ref(false)

function isActive(href: string): boolean {
  return href === '/'
    ? page.url === '/'
    : page.url.startsWith(href)
}

const nav = [
  { href: '/',         label: 'Dashboard' },
  { href: '/consoles', label: 'Consoles'  },
]
</script>

<template>

  <button
    class="fixed top-4 left-4 z-50 cursor-pointer flex lg:hidden items-center justify-center w-10 h-10 rounded-md bg-zinc-800 text-zinc-300 hover:text-zinc-100 hover:bg-zinc-700 transition-colors"
    @click="open = !open"
    aria-label="Toggle menu"
  >
    <!-- Hamburger icon -->
    <svg v-if="!open" xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
    </svg>
    <!-- Close icon -->
    <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
    </svg>
  </button>

  <!-- Backdrop (mobile only) -->
  <div
    v-if="open"
    class="fixed inset-0 z-30 bg-black/50 lg:hidden"
    @click="open = false"
  />

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
        v-for="item in nav"
        :key="item.href"
        :href="item.href"
        class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm font-medium transition-colors"
        :class="isActive(item.href)
          ? 'bg-emerald-500/10 text-emerald-400'
          : 'text-zinc-400 hover:text-zinc-100 hover:bg-zinc-800'"
        @click="open = false"
      >
        {{ item.label }}
      </Link>
    </nav>

    <!-- Footer -->
    <div class="px-5 py-4 border-t text-center border-zinc-800">
      <p class="text-xs text-zinc-600"><a href="https://github.com/mattiasghodsian/retroBite" target="_new">retroBITE</a> v0.0.1</p>
    </div>

  </aside>
</template>
