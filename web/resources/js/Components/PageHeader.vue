<script setup lang="ts">
defineProps<{
  backHref?: string
}>()
</script>

<template>
  <div class="flex items-center justify-between mb-6">
    <slot name="title" />
    <slot name="actions" />
  </div>
</template>
