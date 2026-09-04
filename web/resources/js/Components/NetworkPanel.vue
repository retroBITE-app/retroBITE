<script setup lang="ts">
import { onMounted, ref } from 'vue'
import ShareCard from '@/Components/ShareCard.vue'
import { apiHeaders } from '@/Helpers/http'
import { route } from '@/routes'
import type { NetworkInfo } from '@/Types/api'

const props = defineProps<{ network: NetworkInfo }>()

/** Presentation details per protocol, mirroring App\Enums\ShareProtocol. */
const PROTOCOLS = [
  {
    key: 'smb' as const,
    label: 'SMB',
    ports: '139, 445',
    note: 'SMBv1 – SMBv3',
    connection: (folder: string) => `\\\\${props.network.host_ip}\\${folder}`,
  },
  {
    key: 'ftp' as const,
    label: 'FTP',
    ports: '21 · passive 21100–21110',
    connection: (folder: string) => `ftp://${props.network.host_ip}/${folder}`,
  },
]

const status = ref<Record<string, boolean | null>>({ smb: null, ftp: null })

onMounted(checkStatus)

/**
 * Poll whether each protocol is currently accepting connections. The server
 * sends the share list with the page but not its liveness.
 */
async function checkStatus(): Promise<void> {
  status.value = { smb: null, ftp: null }

  try {
    const res = await fetch(route('network.status'), { headers: apiHeaders() })
    const body = (await res.json()) as Record<string, boolean>

    status.value = { smb: Boolean(body.smb), ftp: Boolean(body.ftp) }
  } catch {
    status.value = { smb: false, ftp: false }
  }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h2 class="text-base font-medium text-fg-bright">Network shares</h2>
      <button
        type="button"
        class="cursor-pointer text-xs text-fg-dim transition-colors hover:text-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
        @click="checkStatus"
      >
        Refresh
      </button>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
      <ShareCard
        v-for="protocol in PROTOCOLS"
        :key="protocol.key"
        :label="protocol.label"
        :host-ip="network.host_ip"
        :username="network.username"
        :ports="protocol.ports"
        :note="protocol.note"
        :online="status[protocol.key] ?? null"
        :shares="network.shares"
        :connection="protocol.connection"
      />
    </div>
  </div>
</template>
