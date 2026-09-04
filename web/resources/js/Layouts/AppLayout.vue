<script setup lang="ts">
import AppSidebar from '@/Components/AppSidebar.vue'

defineProps<{ noPadding?: boolean }>()
</script>

<template>
  <!--
    The page scrolls and the sidebar sticks, rather than a fixed shell with an
    inner scroller. Below `lg` the sidebar leaves the flow entirely as an
    off-canvas drawer, so `main` is the only child and carries top padding to
    clear the floating hamburger.
  -->
  <div class="flex min-h-screen flex-col text-fg lg:flex-row">
    <AppSidebar />

    <main class="min-w-0 flex-1" :class="noPadding ? '' : 'px-5 pt-16 pb-5 lg:p-8'">
      <slot />
    </main>
  </div>
</template>
