<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { PhEye, PhEyeSlash, PhLockSimple, PhSignIn, PhUser } from '@phosphor-icons/vue'
import IconField from '@/Components/UI/IconField.vue'
import { route } from '@/routes'
import type { HostStat } from '@/Types/api'

defineOptions({ layout: false })

const props = defineProps<{
  error?: string | null
  /** A cached artwork URL, or null on a host with no scraped metadata. */
  backdrop?: string | null
  /** Null when `login_show_stats` is off. */
  stats?: HostStat[] | null
}>()

const form = useForm({ username: '', password: '' })
const passwordShown = ref(false)

const passwordType = computed(() => (passwordShown.value ? 'text' : 'password'))
const passwordIcon = computed(() => (passwordShown.value ? PhEyeSlash : PhEye))

/**
 * Post the credentials. Inertia surfaces a rejection as the `error` page prop and
 * anything field-specific on `form.errors`.
 */
function submit(): void {
  form.post(route('login.submit'))
}
</script>

<template>
  <div class="relative flex min-h-screen flex-col px-5 py-12">
    <!--
      Full-bleed art. Fixed rather than absolute so it still covers the viewport
      when a short window makes the page scroll. Every layer renders with or
      without a backdrop, so an empty library still gets a deliberate page.
    -->
    <div
      v-if="props.backdrop"
      class="fixed inset-0 bg-cover bg-[position:50%_22%]"
      :style="{ backgroundImage: `url(${props.backdrop})` }"
    />

    <!--
      Scrim, deepest behind the form so the centred text stays legible whatever
      the artwork, and thinnest at the corners so the art still breathes.
    -->
    <div
      class="fixed inset-0"
      style="
        background: radial-gradient(
          ellipse 75% 65% at 50% 45%,
          color-mix(in srgb, var(--color-ground) 88%, transparent) 0%,
          color-mix(in srgb, var(--color-ground) 62%, transparent) 55%,
          color-mix(in srgb, var(--color-ground) 28%, transparent) 100%
        );
      "
    />

    <div class="scanlines fixed inset-0" />

    <!-- Vignette that seats the stats against the artwork. -->
    <div
      class="pointer-events-none fixed inset-0"
      style="
        background: linear-gradient(
          0deg,
          color-mix(in srgb, var(--color-ground) 88%, transparent) 0%,
          color-mix(in srgb, var(--color-ground) 40%, transparent) 22%,
          transparent 45%
        );
      "
    />

    <div class="relative z-10 flex flex-1 items-center justify-center">
      <div class="flex w-full max-w-[372px] flex-col">
        <img src="/images/logo.webp" alt="retroBITE" class="mx-auto block h-auto w-[148px]" />

        <p class="mt-9 text-center text-3xs tracking-[0.2em] text-fg-faint uppercase">Sign in</p>
        <h1
          class="mt-2 text-center text-[25px] leading-tight font-medium tracking-[-0.01em] text-fg"
        >
          Welcome back
        </h1>
        <p class="mt-2 text-center text-[13.5px] leading-relaxed text-fg-dim">
          Your library, achievements and console files are waiting.
        </p>

        <form class="mt-8 flex flex-col gap-4" @submit.prevent="submit">
          <!-- The design has no error state; this follows its destructive colour. -->
          <p
            v-if="props.error"
            role="alert"
            class="rounded-[9px] border border-danger/40 bg-danger/10 px-4 py-3 text-[13px] text-danger"
          >
            {{ props.error }}
          </p>

          <IconField
            id="username"
            v-model="form.username"
            label="Username"
            :icon="PhUser"
            placeholder="Enter username"
            autocomplete="username"
            :disabled="form.processing"
            :error="form.errors.username"
          />

          <IconField
            id="password"
            v-model="form.password"
            label="Password"
            :icon="PhLockSimple"
            :type="passwordType"
            placeholder="Enter password"
            autocomplete="current-password"
            :disabled="form.processing"
            :error="form.errors.password"
          >
            <template #trailing>
              <button
                type="button"
                class="shrink-0 cursor-pointer rounded text-fg-muted transition-colors hover:text-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
                :aria-label="passwordShown ? 'Hide password' : 'Show password'"
                @click="passwordShown = !passwordShown"
              >
                <component :is="passwordIcon" :size="15" />
              </button>
            </template>
          </IconField>

          <button
            type="submit"
            :disabled="form.processing"
            class="mt-1 flex cursor-pointer items-center justify-center gap-2 rounded-[9px] border border-accent-tint/55 bg-accent-tint/10 px-4 py-3 text-sm text-accent transition-colors hover:bg-accent-tint/18 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep disabled:cursor-not-allowed disabled:opacity-50"
          >
            <PhSignIn :size="16" />
            {{ form.processing ? 'Signing in…' : 'Sign in' }}
          </button>
        </form>
      </div>
    </div>

    <!--
      Pinned to the bottom by being the last row of a column layout rather than
      by `fixed`: on a short window it gets pushed down and scrolls with the
      form instead of sitting on top of it.
    -->
    <div v-if="props.stats?.length" class="relative z-10 mt-12 shrink-0 text-center">
      <p class="text-3xs tracking-[0.2em] text-fg-dim uppercase">On this host</p>
      <dl class="mt-3 flex flex-wrap justify-center gap-x-9 gap-y-3">
        <div v-for="stat in props.stats" :key="stat.label">
          <dd class="font-mono text-lg text-fg">{{ stat.value }}</dd>
          <dt class="mt-0.5 text-2xs text-fg-muted">{{ stat.label }}</dt>
        </div>
      </dl>
    </div>
  </div>
</template>
