<script setup lang="ts">
import AlertBox from '@/Components/UI/AlertBox.vue'
import BaseButton from '@/Components/UI/BaseButton.vue'
import BaseModal from '@/Components/UI/BaseModal.vue'

withDefaults(
  defineProps<{
    open: boolean
    title: string
    message: string
    warning?: string
    confirmLabel?: string
    cancelLabel?: string
    busy?: boolean
    variant?: 'danger' | 'default'
  }>(),
  {
    confirmLabel: 'Confirm',
    cancelLabel: 'Cancel',
    busy: false,
    variant: 'default',
  },
)

const emit = defineEmits<{ confirm: []; cancel: [] }>()
</script>

<template>
  <BaseModal :open="open" :busy="busy" @close="emit('cancel')">
    <template #header>
      <h2
        class="text-base font-semibold"
        :class="variant === 'danger' ? 'text-red-400' : 'text-zinc-100'"
      >
        {{ title }}
      </h2>
    </template>

    <p class="text-sm text-zinc-300">{{ message }}</p>

    <AlertBox v-if="warning" tone="error" size="sm" class="mt-3">{{ warning }}</AlertBox>

    <template #footer>
      <BaseButton variant="ghost" :disabled="busy" @click="emit('cancel')">
        {{ cancelLabel }}
      </BaseButton>
      <BaseButton
        :variant="variant === 'danger' ? 'danger' : 'primary'"
        :busy="busy"
        busy-label="Working…"
        @click="emit('confirm')"
      >
        {{ confirmLabel }}
      </BaseButton>
    </template>
  </BaseModal>
</template>
