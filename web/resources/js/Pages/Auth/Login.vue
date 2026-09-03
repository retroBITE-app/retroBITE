<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AppBrand from '@/Components/AppBrand.vue'
import AlertBox from '@/Components/UI/AlertBox.vue'
import BaseButton from '@/Components/UI/BaseButton.vue'
import FormField from '@/Components/UI/FormField.vue'
import TextInput from '@/Components/UI/TextInput.vue'
import { route } from '@/routes'

defineOptions({ layout: false })

defineProps<{ error?: string | null }>()

const form = useForm({ username: '', password: '' })

/**
 * Post the credentials; Inertia surfaces any failure as a page prop.
 */
function submit(): void {
  form.post(route('login.submit'))
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-zinc-950 p-4">
    <div class="flex w-full max-w-sm flex-col gap-2">
      <img src="/logo.png" alt="retroBITE" class="mx-auto mb-2 h-32 w-auto" />

      <div class="rounded-xl border border-zinc-700 bg-zinc-900 p-6">
        <AlertBox v-if="error" class="mb-5">{{ error }}</AlertBox>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
          <FormField for="username" label="Username" :error="form.errors.username">
            <TextInput
              id="username"
              v-model="form.username"
              autocomplete="username"
              placeholder="Enter username"
              :disabled="form.processing"
            />
          </FormField>

          <FormField for="password" label="Password" :error="form.errors.password">
            <TextInput
              id="password"
              v-model="form.password"
              type="password"
              autocomplete="current-password"
              placeholder="Enter password"
              :disabled="form.processing"
            />
          </FormField>

          <BaseButton
            type="submit"
            class="mt-1 w-full"
            :busy="form.processing"
            busy-label="Signing in…"
          >
            Sign in
          </BaseButton>
        </form>
      </div>

      <AppBrand class="text-center" />
    </div>
  </div>
</template>
