<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import ConsoleCard from '@/Components/ConsoleCard.vue'
import PageHeader from '@/Components/PageHeader.vue'
import SetupDirectories from '@/Components/SetupDirectories.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import type { ConsoleCard as ConsoleCardData } from '@/Types/api'

const props = defineProps<{
  consoles: ConsoleCardData[]
  available: Array<{ key: string; name: string }>
}>()
</script>

<template>
  <div>
    <PageHeader>
      <template #title>
        <h1 class="text-2xl font-semibold text-zinc-100">Consoles</h1>
      </template>
      <template #actions>
        <SetupDirectories :consoles="available" title="New console" @done="router.reload()" />
      </template>
    </PageHeader>

    <EmptyState
      v-if="consoles.length === 0"
      message="No consoles installed yet."
      hint="Pick one with the New console button above."
    />

    <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3">
      <ConsoleCard v-for="entry in props.consoles" :key="entry.key" :console="entry" />
    </div>
  </div>
</template>
