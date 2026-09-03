<script setup lang="ts">
/**
 * Project identity — one place for the repo URL and version, which the sidebar
 * and login page each hardcoded.
 *
 * Carries no colour of its own: the sidebar is still on the zinc palette while
 * the login page is on the redesign's warm ramp, so the caller sets it.
 */
const REPO_URL = 'https://github.com/mattiasghodsian/retroBite'
const VERSION = 'v0.0.1'
</script>

<template>
  <p class="text-xs">
    <a
      :href="REPO_URL"
      target="_blank"
      rel="noopener noreferrer"
      class="transition-colors hover:text-accent"
    >
      retroBITE
    </a>
    {{ VERSION }}
  </p>
</template>
