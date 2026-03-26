<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'

const page = usePage()

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
  <aside class="w-60 shrink-0 flex flex-col bg-zinc-900 border-r border-zinc-800">

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
