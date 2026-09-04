<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

/**
 * Project identity — name, version and repo url, read from the shared props so
 * config/about.php stays the single source.
 *
 * Carries no colour of its own: the caller sets it.
 */
const page = usePage()

const identity = computed(() => page.props.app)
</script>

<template>
  <p class="text-xs">
    <a
      :href="identity.repo_url"
      target="_blank"
      rel="noopener noreferrer"
      class="transition-colors hover:text-accent"
    >
      {{ identity.name }}
    </a>
    {{ identity.version }}
  </p>
</template>
