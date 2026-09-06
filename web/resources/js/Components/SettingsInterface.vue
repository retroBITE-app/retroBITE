<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { PhMonitorPlay } from '@phosphor-icons/vue'
import AlertBox from '@/Components/UI/AlertBox.vue'
import ToggleSwitch from '@/Components/UI/ToggleSwitch.vue'
import { useApiAction } from '@/Composables/useApiAction'
import { route } from '@/routes'
import type { SettingsGroup } from '@/Types/api'

/** The single item this group holds; its fields are the toggles on this panel. */
const ITEM_KEY = 'appearance'

const props = defineProps<{ group: SettingsGroup }>()

const scanlines = ref(currentScanlines())

const save = useApiAction(
  () => route('settings.save', { group: props.group.slug, key: ITEM_KEY }),
  { fallback: 'Could not save' },
)

const label = computed(() => props.group.schema.scanlines?.label ?? 'CRT scanline overlay')

// The reload after a save sends the group back; follow it so the switch shows
// what is stored rather than what was clicked.
watch(
  () => currentScanlines(),
  (stored) => (scanlines.value = stored),
)

/**
 * The stored value for this group's scanline field.
 */
function currentScanlines(): boolean {
  return Boolean(props.group.items[ITEM_KEY]?.scanlines)
}

/**
 * Persist the switch, reverting it if the request fails.
 */
async function apply(value: boolean): Promise<void> {
  const previous = scanlines.value
  scanlines.value = value

  if (await save.run({ json: { scanlines: value } })) {
    router.reload({ only: ['groups'] })

    return
  }

  scanlines.value = previous
}
</script>

<template>
  <div class="max-w-[900px] md:grid md:grid-cols-2 md:gap-3.5">
    <div class="rounded-xl border border-line bg-sunken px-4.5 py-4">
      <div class="flex items-center gap-2.5">
        <PhMonitorPlay :size="17" class="text-accent" />
        <p class="flex-1 text-base text-fg-bright">{{ props.group.label }}</p>
      </div>

      <p class="mt-2.5 text-sm leading-relaxed text-fg-dim">
        Applies to every artwork backdrop in the app.
      </p>

      <div class="mt-4 flex items-center gap-2.75">
        <ToggleSwitch
          :model-value="scanlines"
          :label="label"
          :disabled="save.busy.value"
          @update:model-value="apply"
        />
        <div class="flex-1">
          <p class="text-sm text-fg-soft">{{ label }}</p>
          <p class="mt-0.5 text-xs text-fg-faint">
            Fine horizontal lines over key art and hero images
          </p>
        </div>
      </div>

      <AlertBox v-if="save.error.value" size="sm" class="mt-3">{{ save.error.value }}</AlertBox>
    </div>
  </div>
</template>
