<script setup lang="ts">
import { PhFolder } from '@phosphor-icons/vue'
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
  <div class="flex flex-col overflow-hidden rounded-xl border border-line bg-sunken">
    <div class="flex items-center justify-between border-b border-line/70 px-3.5 py-3">
      <h3 class="text-sm text-fg-bright">{{ label }}</h3>
      <StatusDot :online="online" :label="label" />
    </div>

    <div class="space-y-4 px-3.5 py-3.5">
      <dl class="grid grid-cols-2 gap-4 text-sm">
        <div>
          <dt class="text-fg-faint">Host</dt>
          <dd class="mt-0.5 font-mono text-sm text-fg-soft">{{ hostIp }}</dd>
        </div>
        <div>
          <dt class="text-fg-faint">Ports</dt>
          <dd class="mt-0.5 font-mono text-sm text-fg-soft">{{ ports }}</dd>
        </div>
        <div>
          <dt class="text-fg-faint">Credentials</dt>
          <dd class="mt-0.5 font-mono text-sm text-fg-soft">{{ username }} / ******</dd>
        </div>
        <div v-if="note">
          <dt class="text-fg-faint">Notes</dt>
          <dd class="mt-0.5 text-sm text-fg-soft">{{ note }}</dd>
        </div>
      </dl>

      <div>
        <p class="mb-2 kicker text-fg-faint">Shares</p>

        <EmptyState v-if="shares.length === 0" message="No consoles installed yet." />

        <ul v-else class="space-y-1">
          <li
            v-for="share in shares"
            :key="share.key"
            class="flex items-center gap-2 rounded-md border border-line/70 bg-surface px-3 py-2"
          >
            <img
              v-if="share.icon"
              :src="share.icon"
              :alt="share.name"
              class="h-4 w-4 shrink-0 object-contain"
            />
            <PhFolder v-else :size="15" class="shrink-0 text-fg-faint" aria-hidden="true" />

            <span class="min-w-0 flex-1 truncate text-sm text-fg-soft">{{ share.name }}</span>
            <span class="min-w-0 shrink truncate font-mono text-xs text-fg-dim">{{
              connection(share.folder)
            }}</span>

            <CopyButton :text="connection(share.folder)" />
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>
