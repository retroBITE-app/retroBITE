<script setup lang="ts">
import CopyButton from '@/Components/UI/CopyButton.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import StatusDot from '@/Components/UI/StatusDot.vue'
import type { Share } from '@/Types/api'

/**
 * One protocol's connection details and share list.
 *
 * NetworkPanel previously carried two structurally identical 86-line copies of
 * this, differing only in which protocol they named.
 */
defineProps<{
  label: string
  hostIp: string
  username: string
  ports: string
  note?: string
  online: boolean | null
  shares: Share[]
  /** Builds the connection string for one share folder. */
  connection: (folder: string) => string
}>()
</script>

<template>
  <div class="flex flex-col overflow-hidden rounded-xl border border-zinc-700 bg-zinc-800/50">
    <div class="flex items-center justify-between border-b border-zinc-700 px-5 py-3">
      <h3 class="text-sm font-semibold text-zinc-100">{{ label }}</h3>
      <StatusDot :online="online" :label="label" />
    </div>

    <div class="space-y-4 px-5 py-4">
      <dl class="grid grid-cols-2 gap-4 text-xs">
        <div>
          <dt class="text-zinc-500">Host</dt>
          <dd class="mt-0.5 font-mono text-sm text-zinc-200">{{ hostIp }}</dd>
        </div>
        <div>
          <dt class="text-zinc-500">Ports</dt>
          <dd class="mt-0.5 font-mono text-sm text-zinc-200">{{ ports }}</dd>
        </div>
        <div>
          <dt class="text-zinc-500">Credentials</dt>
          <dd class="mt-0.5 font-mono text-sm text-zinc-200">{{ username }} / ******</dd>
        </div>
        <div v-if="note">
          <dt class="text-zinc-500">Notes</dt>
          <dd class="mt-0.5 text-sm text-zinc-200">{{ note }}</dd>
        </div>
      </dl>

      <div>
        <p class="mb-2 text-xs font-medium text-zinc-400">Shares</p>

        <EmptyState v-if="shares.length === 0" message="No consoles installed yet." />

        <ul v-else class="space-y-1">
          <li
            v-for="share in shares"
            :key="share.key"
            class="flex items-center gap-2 rounded-md bg-zinc-900/60 px-3 py-2"
          >
            <img
              v-if="share.icon"
              :src="share.icon"
              :alt="share.name"
              class="h-4 w-4 shrink-0 object-contain"
            />
            <span v-else class="shrink-0 text-zinc-600" aria-hidden="true">📁</span>

            <span class="min-w-0 flex-1 truncate text-sm text-zinc-300">{{ share.name }}</span>
            <span class="shrink-0 font-mono text-xs text-zinc-500">{{
              connection(share.folder)
            }}</span>

            <CopyButton :text="connection(share.folder)" />
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>
