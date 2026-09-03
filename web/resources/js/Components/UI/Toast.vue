<script setup lang="ts">
import AlertBox from '@/Components/UI/AlertBox.vue'

/**
 * A bottom-right transient message. Two pages had drifting copies of this, one
 * at z-40 and one at z-50.
 */
withDefaults(
  defineProps<{
    tone?: 'error' | 'warning' | 'success' | 'info'
  }>(),
  { tone: 'error' },
)
</script>

<template>
  <Teleport to="body">
    <div class="fixed bottom-4 right-4 z-50 max-w-sm">
      <AlertBox :tone="tone">
        <slot />
      </AlertBox>
    </div>
  </Teleport>
</template>
