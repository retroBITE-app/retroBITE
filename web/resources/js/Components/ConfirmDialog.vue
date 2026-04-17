<script setup lang="ts">
withDefaults(defineProps<{
  open:         boolean
  title:        string
  message:      string
  warning?:     string
  confirmLabel?: string
  cancelLabel?:  string
  busy?:         boolean
  variant?:     'danger' | 'default'
}>(), {
  confirmLabel: 'Confirm',
  cancelLabel:  'Cancel',
  busy:         false,
  variant:      'default',
})

const emit = defineEmits<{ confirm: []; cancel: [] }>()
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/60" @click="!busy && emit('cancel')" />

      <div class="relative z-10 w-full max-w-md rounded-xl bg-zinc-900 border border-zinc-700 shadow-2xl">
        <div class="px-6 py-5">
          <h2
            :class="variant === 'danger' ? 'text-red-400' : 'text-zinc-100'"
            class="text-base font-semibold"
          >{{ title }}</h2>
          <p class="text-sm text-zinc-300 mt-2">{{ message }}</p>
          <p
            v-if="warning"
            class="mt-3 rounded-md border border-red-500/30 bg-red-500/10 px-3 py-2 text-xs text-red-300"
          >{{ warning }}</p>
        </div>
        <div class="flex items-center justify-end gap-2 px-6 py-4 border-t border-zinc-800">
          <button
            @click="emit('cancel')"
            :disabled="busy"
            class="px-4 py-2 rounded-md text-sm text-zinc-400 hover:text-zinc-200 transition-colors disabled:opacity-40"
          >{{ cancelLabel }}</button>
          <button
            @click="emit('confirm')"
            :disabled="busy"
            :class="variant === 'danger'
              ? 'bg-red-600 hover:bg-red-500 text-white'
              : 'bg-emerald-600 hover:bg-emerald-500 text-white'"
            class="px-4 py-2 rounded-md text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >{{ busy ? 'Working…' : confirmLabel }}</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
