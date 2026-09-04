<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AlertBox from '@/Components/UI/AlertBox.vue'
import BaseButton from '@/Components/UI/BaseButton.vue'
import BaseModal from '@/Components/UI/BaseModal.vue'
import Spinner from '@/Components/UI/Spinner.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import { useApiAction } from '@/Composables/useApiAction'
import { route } from '@/routes'
import type { ProviderCandidate } from '@/Types/api'

const props = defineProps<{
  open: boolean
  consoleKey: string
  gameFileName: string
}>()

const emit = defineEmits<{ close: [] }>()

type LookupResult = {
  md5_match: ProviderCandidate | null
  candidates: ProviderCandidate[]
}

const searchName = ref('')
const md5Match = ref<ProviderCandidate | null>(null)
const candidates = ref<ProviderCandidate[]>([])
const assigning = ref<string | null>(null)

const lookup = useApiAction<LookupResult>(
  () =>
    route('game.identify', { console: props.consoleKey, game: props.gameFileName }) +
    searchQuery.value,
  { fallback: 'Lookup failed' },
)

const assign = useApiAction(
  () => route('game.metadata', { console: props.consoleKey, game: props.gameFileName }),
  { fallback: 'Assign failed' },
)

const searchQuery = computed(() =>
  searchName.value.trim() ? `?search=${encodeURIComponent(searchName.value.trim())}` : '',
)

const hasResults = computed(() => md5Match.value !== null || candidates.value.length > 0)
const error = computed(() => assign.error.value ?? lookup.error.value)

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      searchName.value = ''
      load()
    }
  },
)

/**
 * Ask the provider for matches, replacing whatever was on screen.
 */
async function load(): Promise<void> {
  md5Match.value = null
  candidates.value = []

  const result = await lookup.run()

  if (result) {
    md5Match.value = result.md5_match ?? null
    candidates.value = result.candidates ?? []
  }
}

/**
 * Store the chosen match and refresh the page's game prop.
 */
async function pick(providerId: string, source: 'md5' | 'name'): Promise<void> {
  if (assigning.value !== null) {
    return
  }

  assigning.value = `${source}:${providerId}`

  const saved = await assign.run({ body: { provider_id: providerId } })
  assigning.value = null

  if (saved) {
    emit('close')
    router.reload({ only: ['game'] })
  }
}

/**
 * Secondary detail line for a candidate: date, genre, region, player count.
 */
function detailLine(candidate: ProviderCandidate): string {
  return [
    candidate.release_date ?? candidate.year,
    candidate.genre,
    candidate.region?.toUpperCase(),
    candidate.players ? `${candidate.players}P` : null,
  ]
    .filter(Boolean)
    .join(' · ')
}

/**
 * Studio line for a candidate, avoiding "Foo / Foo".
 */
function studioLine(candidate: ProviderCandidate): string {
  return candidate.publisher && candidate.publisher !== candidate.developer
    ? `${candidate.developer ?? ''} / ${candidate.publisher}`.trim()
    : (candidate.developer ?? '')
}
</script>

<template>
  <BaseModal
    :open="open"
    title="Identify game"
    :subtitle="gameFileName"
    :busy="assigning !== null"
    size="2xl"
    @close="emit('close')"
  >
    <Spinner v-if="lookup.busy.value" label="Searching ScreenScraper…" />

    <AlertBox v-else-if="error">{{ error }}</AlertBox>

    <div v-else-if="!hasResults">
      <p class="text-center text-[13px] text-fg-soft">No matches found for this ROM.</p>
      <p class="mt-1 text-center text-xs text-fg-faint">Try searching with a custom title.</p>

      <form class="mt-5 flex gap-2" @submit.prevent="load">
        <TextInput v-model="searchName" placeholder="e.g. Super Mario Sunshine" class="flex-1" />
        <BaseButton type="submit" :disabled="!searchName.trim()">Search</BaseButton>
      </form>
    </div>

    <div v-else class="space-y-5">
      <section v-if="md5Match">
        <div class="mb-2 flex items-center gap-2">
          <span
            class="h-1.5 w-1.5 rounded-full bg-accent shadow-[0_0_6px_var(--color-accent-deep)]"
            aria-hidden="true"
          />
          <h3 class="font-mono text-3xs tracking-[0.14em] text-accent uppercase">
            Exact MD5 match
          </h3>
        </div>

        <button
          type="button"
          :disabled="assigning !== null"
          class="flex w-full cursor-pointer gap-3 rounded-[10px] border border-accent-tint/40 bg-accent-tint/6 p-3 text-left transition-colors hover:border-accent-tint/70 hover:bg-accent-tint/12 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep disabled:cursor-not-allowed disabled:opacity-50"
          @click="pick(md5Match.provider_id, 'md5')"
        >
          <img
            v-if="md5Match.cover_url"
            :src="md5Match.cover_url"
            :alt="md5Match.title ?? ''"
            class="h-auto w-50 shrink-0 self-start rounded border border-line-strong bg-sunken object-contain"
          />
          <div
            v-else
            class="aspect-5/7 w-24 shrink-0 rounded border border-line-strong bg-sunken"
          />

          <div class="min-w-0 flex-1">
            <p class="truncate text-[13px] text-fg-bright">
              {{ md5Match.title ?? '(no title)' }}
            </p>
            <p class="mt-0.5 font-mono text-2xs text-fg-dim">{{ detailLine(md5Match) }}</p>
            <p class="mt-0.5 truncate text-xs text-fg-faint">{{ studioLine(md5Match) }}</p>
          </div>

          <span
            v-if="assigning === `md5:${md5Match.provider_id}`"
            class="shrink-0 self-center font-mono text-2xs text-accent"
          >
            Assigning…
          </span>
        </button>
      </section>

      <section v-if="candidates.length">
        <h3 class="mb-2 font-mono text-3xs tracking-[0.14em] text-fg-faint uppercase">
          Name matches <span class="text-fg-dim">({{ candidates.length }})</span>
        </h3>

        <div class="space-y-1.5">
          <button
            v-for="candidate in candidates"
            :key="candidate.provider_id"
            type="button"
            :disabled="assigning !== null"
            class="flex w-full cursor-pointer gap-3 rounded-[10px] border border-line-strong bg-surface p-3 text-left transition-colors hover:border-line-bright hover:bg-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep disabled:cursor-not-allowed disabled:opacity-50"
            @click="pick(candidate.provider_id, 'name')"
          >
            <img
              v-if="candidate.cover_url"
              :src="candidate.cover_url"
              :alt="candidate.title ?? ''"
              class="h-auto w-50 shrink-0 self-start rounded border border-line-strong bg-sunken object-contain"
            />
            <div
              v-else
              class="aspect-5/7 w-20 shrink-0 rounded border border-line-strong bg-sunken"
            />

            <div class="min-w-0 flex-1">
              <p class="truncate text-[13px] text-fg-bright">
                {{ candidate.title ?? '(no title)' }}
              </p>
              <p class="mt-0.5 font-mono text-2xs text-fg-dim">{{ detailLine(candidate) }}</p>
              <p v-if="candidate.rom_name" class="mt-0.5 truncate font-mono text-2xs text-fg-faint">
                {{ candidate.rom_name }}
              </p>
            </div>

            <span
              v-if="assigning === `name:${candidate.provider_id}`"
              class="shrink-0 self-center font-mono text-2xs text-accent"
            >
              Assigning…
            </span>
          </button>
        </div>
      </section>
    </div>

    <template #footer>
      <p class="mr-auto font-mono text-2xs text-fg-faint">Powered by ScreenScraper.fr</p>
      <BaseButton
        v-if="!lookup.busy.value && !error"
        variant="ghost"
        :disabled="assigning !== null"
        @click="load"
      >
        Refresh
      </BaseButton>
      <BaseButton variant="ghost" :disabled="assigning !== null" @click="emit('close')">
        Close
      </BaseButton>
    </template>
  </BaseModal>
</template>
