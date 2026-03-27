<script setup lang="ts">
import AppSidebar from '@/Components/AppSidebar.vue'
</script>

<template>
  <div class="flex h-screen bg-zinc-950 text-zinc-100 overflow-hidden">

    <AppSidebar />

    <!-- Content -->
    <main class="flex-1 flex flex-col overflow-hidden">
      <div class="flex-1 overflow-y-auto p-8">
        <slot />
      </div>
    </main>

  </div>
</template>
