<script setup lang="ts">
import type { Component } from 'vue'
import TextInput from '@/Components/UI/TextInput.vue'

/**
 * A labelled input with a leading icon inside its border — the field shape the
 * redesign uses everywhere. The label is a tracked micro-caps kicker rather than
 * a sentence-case label.
 *
 * Wraps TextInput rather than growing it, because TextInput must keep a single
 * root element: several call sites pass utility classes to it and rely on Vue's
 * implicit root-class merging.
 */
withDefaults(
  defineProps<{
    modelValue: string
    /** Must match `for` on the label. */
    id: string
    label: string
    icon: Component
    type?: 'text' | 'password'
    placeholder?: string
    disabled?: boolean
    error?: string | null
    autocomplete?: string
  }>(),
  {
    type: 'text',
    disabled: false,
  },
)

const emit = defineEmits<{ 'update:modelValue': [string] }>()
</script>

<template>
  <div>
    <div class="flex items-baseline gap-3">
      <label :for="id" class="text-3xs tracking-[0.16em] text-fg-dim uppercase">{{ label }}</label>
      <slot name="label-aside" />
    </div>

    <div
      class="mt-2 flex items-center gap-[9px] rounded-[9px] border bg-surface px-3 transition-colors focus-within:border-accent-deep"
      :class="error ? 'border-danger/70' : 'border-line-input'"
    >
      <component :is="icon" :size="15" class="shrink-0 text-fg-muted" />

      <TextInput
        :id="id"
        bare
        :model-value="modelValue"
        :type="type"
        :placeholder="placeholder"
        :disabled="disabled"
        :autocomplete="autocomplete"
        :invalid="Boolean(error)"
        class="py-[11px]"
        @update:model-value="emit('update:modelValue', $event)"
      />

      <slot name="trailing" />
    </div>

    <p v-if="error" class="mt-1.5 text-2xs text-danger">{{ error }}</p>
  </div>
</template>
