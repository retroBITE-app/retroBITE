<script setup lang="ts">
/**
 * A text input. The same ~200-character class string appeared eight times with
 * drift, and only one copy supported an error state.
 */
withDefaults(
  defineProps<{
    modelValue: string
    type?: 'text' | 'password' | 'number'
    placeholder?: string
    disabled?: boolean
    invalid?: boolean
    mono?: boolean
    id?: string
    autocomplete?: string
  }>(),
  {
    type: 'text',
    disabled: false,
    invalid: false,
    mono: false,
  },
)

const emit = defineEmits<{ 'update:modelValue': [string] }>()
</script>

<template>
  <input
    :id="id"
    :type="type"
    :value="modelValue"
    :placeholder="placeholder"
    :disabled="disabled"
    :autocomplete="autocomplete"
    :aria-invalid="invalid || undefined"
    :class="[
      invalid
        ? 'border-red-500/70 focus:border-red-500'
        : 'border-zinc-700 focus:border-emerald-500',
      mono ? 'font-mono' : '',
    ]"
    class="w-full rounded-md border bg-zinc-800 px-3 py-2 text-sm text-zinc-100 placeholder-zinc-600 outline-none transition-colors disabled:opacity-50"
    @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
  />
</template>
