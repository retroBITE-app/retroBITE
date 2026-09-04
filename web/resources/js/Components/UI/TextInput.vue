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
    /** Drops the border and background so the input can sit inside IconField. */
    bare?: boolean
  }>(),
  {
    type: 'text',
    disabled: false,
    invalid: false,
    mono: false,
    bare: false,
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
      bare
        ? 'min-w-0 flex-1 border-0 bg-transparent p-0 text-[13.5px] text-fg placeholder-fg-faint'
        : [
            'w-full rounded-lg border bg-sunken px-3 py-2.5 text-[13px] text-fg placeholder-fg-faint',
            invalid
              ? 'border-danger/70 focus:border-danger'
              : 'border-line-strong focus:border-accent-deep',
          ],
      mono ? 'font-mono' : '',
    ]"
    class="outline-none transition-colors disabled:opacity-50"
    @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
  />
</template>
