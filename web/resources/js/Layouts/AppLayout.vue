<script setup lang="ts">
import AppSidebar from '@/Components/AppSidebar.vue'

defineProps<{ noPadding?: boolean }>()
</script>

<template>
  <!--
    The page scrolls and the sidebar sticks, rather than a fixed shell with an
    inner scroller. That is what lets the sidebar be a sticky top bar on narrow
    screens and a full-height column on wide ones.
  -->
  <div class="flex min-h-screen flex-col text-fg lg:flex-row">
    <AppSidebar />

    <main class="min-w-0 flex-1" :class="noPadding ? '' : 'p-5 lg:p-8'">
      <slot />
    </main>
  </div>
</template>
