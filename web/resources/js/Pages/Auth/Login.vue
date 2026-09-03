<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { route } from '@/routes'

defineOptions({ layout: false })

defineProps<{
  error?: string | null
}>()

const form = useForm({
  username: '',
  password: '',
})

function submit() {
  form.post(route('login.submit'))
}
</script>

<template>
  <div class="min-h-screen bg-zinc-950 flex items-center justify-center p-4">
    <div class="w-full max-w-sm gap-2 flex flex-col">

      <!-- Logo / Title -->
      <div class="text-center">
        <p class="text-3xl font-bold tracking-tight text-zinc-100">
          <img src="/logo.png" alt="retroBITE" class="h-32 mx-auto w-auto mb-2" />
        </p>
      </div>

      <!-- Card -->
      <div class="rounded-xl border border-zinc-700 bg-zinc-900 p-6">

        <!-- Error -->
        <div
          v-if="error"
          class="mb-5 flex items-center gap-2 rounded-lg border border-red-500/20 bg-red-500/10 px-4 py-3 text-sm text-red-400"
        >
          <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" />
          </svg>
          {{ error }}
        </div>

        <form @submit.prevent="submit" class="flex flex-col gap-4">

          <!-- Username -->
          <div class="flex flex-col gap-1.5">
            <label for="username" class="text-xs font-medium text-zinc-400 uppercase tracking-wider">Username</label>
            <input
              id="username"
              v-model="form.username"
              type="text"
              autocomplete="username"
              required
              class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2.5 text-sm text-zinc-100 placeholder-zinc-600 outline-none transition focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
              placeholder="Enter username"
            />
          </div>

          <!-- Password -->
          <div class="flex flex-col gap-1.5">
            <label for="password" class="text-xs font-medium text-zinc-400 uppercase tracking-wider">Password</label>
            <input
              id="password"
              v-model="form.password"
              type="password"
              autocomplete="current-password"
              required
              class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2.5 text-sm text-zinc-100 placeholder-zinc-600 outline-none transition focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
              placeholder="Enter password"
            />
          </div>

          <!-- Submit -->
          <button
            type="submit"
            :disabled="form.processing"
            class="mt-1 w-full rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-500 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {{ form.processing ? 'Signing in…' : 'Sign in' }}
          </button>

        </form>

        
      </div>
      <p class="text-xs text-center text-zinc-600">
          <a href="https://github.com/mattiasghodsian/retroBite" target="_new">retroBITE</a> v0.0.1
        </p>
    </div>
  </div>
</template>
