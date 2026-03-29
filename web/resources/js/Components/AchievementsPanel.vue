<script setup lang="ts">
const achievements = [
  {
    id: 1,
    title: 'The True Devil Hunter',
    description: 'Complete the game on Dante Must Die difficulty',
    game: 'Devil May Cry 3',
    console: 'PlayStation 2',
    consoleIcon: '/images/consoles/Sony - PlayStation 2.png',
    badge: 'https://media.retroachievements.org/Badge/00000.png',
    points: 50,
    earnedAt: '2026-03-28T21:14:00Z',
  },
  {
    id: 2,
    title: 'Blue Falcon Ace',
    description: 'Win 1st place in all Grand Prix cups',
    game: 'F-Zero GX',
    console: 'GameCube',
    consoleIcon: '/images/consoles/Nintendo - GameCube.png',
    badge: 'https://media.retroachievements.org/Badge/00000.png',
    points: 25,
    earnedAt: '2026-03-28T19:02:00Z',
  },
  {
    id: 3,
    title: 'Triforce Completed',
    description: 'Collect all 36 heart containers',
    game: 'The Legend of Zelda: Wind Waker',
    console: 'GameCube',
    consoleIcon: '/images/consoles/Nintendo - GameCube.png',
    badge: 'https://media.retroachievements.org/Badge/00000.png',
    points: 10,
    earnedAt: '2026-03-28T15:47:00Z',
  },
  {
    id: 4,
    title: 'Blitz King',
    description: 'Score 100 points in a single match',
    game: 'Madden NFL 2005',
    console: 'PlayStation 2',
    consoleIcon: '/images/consoles/Sony - PlayStation 2.png',
    badge: 'https://media.retroachievements.org/Badge/00000.png',
    points: 5,
    earnedAt: '2026-03-27T23:30:00Z',
  },
  {
    id: 5,
    title: 'Mii Maestro',
    description: 'Unlock all Mii costumes in story mode',
    game: 'Super Mario Galaxy',
    console: 'Wii',
    consoleIcon: '/images/consoles/Nintendo - Wii.png',
    badge: 'https://media.retroachievements.org/Badge/00000.png',
    points: 25,
    earnedAt: '2026-03-27T18:11:00Z',
  }
]

function timeAgo(iso: string): string {
  const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
  if (diff < 3600)  return `${Math.floor(diff / 60)}m ago`
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
  return `${Math.floor(diff / 86400)}d ago`
}
</script>

<template>
  <div class="rounded-xl border border-zinc-700 bg-zinc-800/50 overflow-hidden">

    <!-- Achievement rows -->
    <div class="divide-y divide-zinc-700/40">
      <div
        v-for="(achievement, index) in achievements"
        :key="achievement.id"
        class="flex items-center gap-4 px-5 py-3 hover:bg-zinc-700/20 transition-colors group"
      >
        <!-- Rank -->
        <span class="w-5 shrink-0 text-center text-xs font-mono text-zinc-600 group-hover:text-zinc-400 transition-colors">
          {{ index + 1 }}
        </span>

        <!-- Badge -->
        <div class="relative shrink-0">
          <img
            :src="achievement.badge"
            :alt="achievement.title"
            class="h-10 w-10 rounded-lg object-cover bg-zinc-700"
            @error="($event.target as HTMLImageElement).style.display = 'none'"
          />
          <!-- Console icon overlay -->
          <img
            :src="achievement.consoleIcon"
            :alt="achievement.console"
            class="absolute -bottom-1 -right-1 h-4 w-4 object-contain rounded-sm bg-zinc-900 p-px"
          />
        </div>

        <!-- Info -->
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-zinc-200 truncate leading-tight">{{ achievement.title }}</p>
          <p class="text-xs text-zinc-500 truncate mt-0.5">{{ achievement.game }}</p>
        </div>

        <!-- Points + time -->
        <div class="shrink-0 flex flex-col items-end gap-0.5">
          <span class="inline-flex items-center gap-1 text-xs font-semibold text-yellow-400">
            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="currentColor">
              <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
            </svg>
            {{ achievement.points }}
          </span>
          <span class="text-xs text-zinc-600">{{ timeAgo(achievement.earnedAt) }}</span>
        </div>
      </div>
    </div>

  </div>
</template>
