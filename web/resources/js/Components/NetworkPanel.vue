<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { route } from '@/routes'
import { apiHeaders } from '@/Helpers/http'

type Share = { key: string; name: string; folder: string; icon: string | null }

const props = defineProps<{
  network: {
    host_ip: string
    username: string
    shares: Share[]
  }
}>()

const smbOnline = ref<boolean | null>(null)
const ftpOnline = ref<boolean | null>(null)
const checking  = ref(false)

async function checkStatus() {
  checking.value = true
  smbOnline.value = null
  ftpOnline.value = null
  try {
    const res  = await fetch(route('network.status'), { headers: apiHeaders() })
    const data = await res.json()
    smbOnline.value = !!data.smb
    ftpOnline.value = !!data.ftp
  } catch {
    smbOnline.value = false
    ftpOnline.value = false
  } finally {
    checking.value = false
  }
}

onMounted(checkStatus)

function smbConnection(folder: string) {
  return `\\\\${props.network.host_ip}\\${folder}`
}

function ftpConnection(folder: string) {
  return `ftp://${props.network.host_ip}/${folder}`
}

function copy(text: string) {
  navigator.clipboard.writeText(text)
}
</script>

<template>
  <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">

    <!-- SMB Card -->
    <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 overflow-hidden">

      <!-- Header -->
      <div class="flex items-center justify-between px-5 py-4 border-b border-zinc-700/60">
        <div class="flex items-center gap-3">
          <span class="rounded-md bg-blue-500/10 px-2.5 py-1 text-xs font-semibold text-blue-400 ring-1 ring-inset ring-blue-500/20">
            SMB / CIFS
          </span>
          <span class="text-sm text-zinc-400">Samba</span>
        </div>
        <div class="flex items-center gap-2">
          <!-- Status indicator -->
          <div class="flex items-center gap-1.5">
            <template v-if="smbOnline === null">
              <span class="h-2 w-2 rounded-full bg-zinc-500 animate-pulse"></span>
              <span class="text-xs text-zinc-500">Checking…</span>
            </template>
            <template v-else-if="smbOnline">
              <span class="h-2 w-2 rounded-full bg-emerald-500 shadow-[0_0_6px_var(--color-emerald-500)]"></span>
              <span class="text-xs text-zinc-400">Active</span>
            </template>
            <template v-else>
              <span class="h-2 w-2 rounded-full bg-red-500"></span>
              <span class="text-xs text-red-400">Offline</span>
            </template>
          </div>
          <!-- Refresh -->
          <button
            @click="checkStatus"
            :disabled="checking"
            title="Re-check status"
            class="text-zinc-600 hover:text-zinc-300 transition-colors disabled:opacity-30 text-sm leading-none"
          >↺</button>
        </div>
      </div>

      <!-- Connection Meta -->
      <div class="grid grid-cols-2 gap-px bg-zinc-700/40 border-b border-zinc-700/60">
        <div class="bg-zinc-800/50 px-4 py-3">
          <p class="text-xs text-zinc-500 uppercase tracking-wider mb-0.5">Server</p>
          <p class="text-sm font-mono text-zinc-200">\\{{ props.network.host_ip }}</p>
        </div>
        <div class="bg-zinc-800/50 px-4 py-3">
          <p class="text-xs text-zinc-500 uppercase tracking-wider mb-0.5">Ports</p>
          <p class="text-sm font-mono text-zinc-200">139, 445</p>
        </div>
        <div class="bg-zinc-800/50 px-4 py-3">
          <p class="text-xs text-zinc-500 uppercase tracking-wider mb-0.5">Protocol</p>
          <p class="text-sm font-mono text-zinc-200">SMBv1 – SMBv3</p>
        </div>
        <div class="bg-zinc-800/50 px-4 py-3">
          <p class="text-xs text-zinc-500 uppercase tracking-wider mb-0.5">Auth</p>
          <p class="text-sm text-zinc-200">{{ props.network.username }} / ******</p>
        </div>
      </div>

      <!-- Shares -->
      <div class="divide-y divide-zinc-700/40">
        <p v-if="!network.shares.length" class="px-5 py-4 text-sm text-zinc-600">No consoles installed</p>
        <div
          v-for="share in network.shares"
          :key="share.key"
          class="flex items-center gap-3 px-5 py-3 hover:bg-zinc-700/30 transition-colors group"
        >
          <div class="flex h-8 w-8 shrink-0 items-center justify-center">
            <img
              v-if="share.icon"
              :src="share.icon"
              :alt="share.name"
              class="h-7 w-7 object-contain opacity-60 group-hover:opacity-90 transition-opacity"
            />
            <svg v-else class="h-5 w-5 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v8.25m19.5 0v2.25A2.25 2.25 0 0 1 19.5 18.75h-15a2.25 2.25 0 0 1-2.25-2.25v-2.25" />
            </svg>
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm text-zinc-200 leading-tight">{{ share.name }}</p>
            <p class="text-xs font-mono text-zinc-500 truncate mt-0.5">{{ smbConnection(share.folder) }}</p>
          </div>
          <button
            @click="copy(smbConnection(share.folder))"
            class="shrink-0 rounded border border-zinc-700 px-2 py-1 text-xs text-zinc-500 opacity-0 group-hover:opacity-100 hover:border-zinc-500 hover:text-zinc-300 transition-all"
          >Copy</button>
        </div>
      </div>

    </div>

    <!-- FTP Card -->
    <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 overflow-hidden">

      <!-- Header -->
      <div class="flex items-center justify-between px-5 py-4 border-b border-zinc-700/60">
        <div class="flex items-center gap-3">
          <span class="rounded-md bg-orange-500/10 px-2.5 py-1 text-xs font-semibold text-orange-400 ring-1 ring-inset ring-orange-500/20">
            FTP
          </span>
          <span class="text-sm text-zinc-400">vsftpd · Passive Mode</span>
        </div>
        <div class="flex items-center gap-2">
          <!-- Status indicator -->
          <div class="flex items-center gap-1.5">
            <template v-if="ftpOnline === null">
              <span class="h-2 w-2 rounded-full bg-zinc-500 animate-pulse"></span>
              <span class="text-xs text-zinc-500">Checking…</span>
            </template>
            <template v-else-if="ftpOnline">
              <span class="h-2 w-2 rounded-full bg-emerald-500 shadow-[0_0_6px_var(--color-emerald-500)]"></span>
              <span class="text-xs text-zinc-400">Active</span>
            </template>
            <template v-else>
              <span class="h-2 w-2 rounded-full bg-red-500"></span>
              <span class="text-xs text-red-400">Offline</span>
            </template>
          </div>
          <!-- Refresh -->
          <button
            @click="checkStatus"
            :disabled="checking"
            title="Re-check status"
            class="text-zinc-600 hover:text-zinc-300 transition-colors disabled:opacity-30 text-sm leading-none"
          >↺</button>
        </div>
      </div>

      <!-- Connection Meta -->
      <div class="grid grid-cols-2 gap-px bg-zinc-700/40 border-b border-zinc-700/60">
        <div class="bg-zinc-800/50 px-4 py-3">
          <p class="text-xs text-zinc-500 uppercase tracking-wider mb-0.5">Control Port</p>
          <p class="text-sm font-mono text-zinc-200">21</p>
        </div>
        <div class="bg-zinc-800/50 px-4 py-3">
          <p class="text-xs text-zinc-500 uppercase tracking-wider mb-0.5">Data Port</p>
          <p class="text-sm font-mono text-zinc-200">20</p>
        </div>
        <div class="bg-zinc-800/50 px-4 py-3">
          <p class="text-xs text-zinc-500 uppercase tracking-wider mb-0.5">Passive Range</p>
          <p class="text-sm font-mono text-zinc-200">21100 – 21110</p>
        </div>
        <div class="bg-zinc-800/50 px-4 py-3">
          <p class="text-xs text-zinc-500 uppercase tracking-wider mb-0.5">Auth</p>
          <p class="text-sm text-zinc-200">{{ props.network.username }} / ******</p>
        </div>
      </div>

      <!-- Folders -->
      <div class="divide-y divide-zinc-700/40">
        <p v-if="!network.shares.length" class="px-5 py-4 text-sm text-zinc-600">No consoles installed</p>
        <div
          v-for="share in network.shares"
          :key="share.key"
          class="flex items-center gap-3 px-5 py-3 hover:bg-zinc-700/30 transition-colors group"
        >
          <div class="flex h-8 w-8 shrink-0 items-center justify-center">
            <img
              v-if="share.icon"
              :src="share.icon"
              :alt="share.name"
              class="h-7 w-7 object-contain opacity-60 group-hover:opacity-90 transition-opacity"
            />
            <svg v-else class="h-5 w-5 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v8.25m19.5 0v2.25A2.25 2.25 0 0 1 19.5 18.75h-15a2.25 2.25 0 0 1-2.25-2.25v-2.25" />
            </svg>
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm text-zinc-200 leading-tight">{{ share.name }}</p>
            <p class="text-xs font-mono text-zinc-500 truncate mt-0.5">{{ ftpConnection(share.folder) }}</p>
          </div>
          <button
            @click="copy(ftpConnection(share.folder))"
            class="shrink-0 rounded border border-zinc-700 px-2 py-1 text-xs text-zinc-500 opacity-0 group-hover:opacity-100 hover:border-zinc-500 hover:text-zinc-300 transition-all"
          >Copy</button>
        </div>
      </div>

    </div>

  </div>
</template>
