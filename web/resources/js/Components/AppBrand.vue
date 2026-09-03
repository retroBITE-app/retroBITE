<script setup lang="ts">
/**
 * Project identity — one place for the repo URL and version, which the sidebar
 * and login page each hardcoded.
 */
const REPO_URL = 'https://github.com/mattiasghodsian/retroBite'
const VERSION = 'v0.0.1'
</script>

<template>
  <p class="text-xs text-zinc-600">
    <a
      :href="REPO_URL"
      target="_blank"
      rel="noopener noreferrer"
      class="transition-colors hover:text-zinc-400"
    >
      retroBITE
    </a>
    {{ VERSION }}
  </p>
</template>
