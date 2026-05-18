<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useTheme } from '@/composables/useTheme'

const { isDark, toggle } = useTheme()
const stagedCount = computed(() => usePage().props.stagedCount ?? 0)

function isActive(href) {
  const url = usePage().url
  return href === '/' ? url === '/' : url.startsWith(href)
}
</script>

<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-900">
    <nav class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-6 py-3 flex items-center justify-between">
      <div class="flex items-center gap-6">
        <span class="font-semibold text-gray-800 dark:text-gray-100">yt-dlp Dashboard</span>
        <Link href="/" :class="['text-sm hover:text-gray-900 dark:hover:text-gray-100', isActive('/') ? 'font-semibold text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400']">Dashboard</Link>
        <Link href="/staging" :class="['relative text-sm hover:text-gray-900 dark:hover:text-gray-100 flex items-center gap-1.5', isActive('/staging') ? 'font-semibold text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400']">
          Staging
          <span
            v-if="stagedCount > 0"
            class="inline-flex items-center justify-center h-4 min-w-4 px-1 rounded-full text-xs font-medium bg-indigo-600 text-white"
          >{{ stagedCount }}</span>
        </Link>
        <Link href="/export-queue" :class="['text-sm hover:text-gray-900 dark:hover:text-gray-100', isActive('/export-queue') ? 'font-semibold text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400']">Export Queue</Link>
        <Link href="/history" :class="['text-sm hover:text-gray-900 dark:hover:text-gray-100', isActive('/history') ? 'font-semibold text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400']">History</Link>
      </div>
      <div class="flex items-center gap-4">
        <button
          @click="toggle"
          :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
          class="p-1 rounded text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-100"
        >
          <!-- Sun: shown when in dark mode -->
          <svg v-if="isDark" xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="5"/>
            <line x1="12" y1="1" x2="12" y2="3"/>
            <line x1="12" y1="21" x2="12" y2="23"/>
            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
            <line x1="1" y1="12" x2="3" y2="12"/>
            <line x1="21" y1="12" x2="23" y2="12"/>
            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
          </svg>
          <!-- Moon: shown when in light mode -->
          <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
          </svg>
        </button>
        <Link
          href="/logout"
          method="post"
          as="button"
          class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-100"
        >
          Sign out
        </Link>
      </div>
    </nav>

    <main class="max-w-5xl mx-auto px-6 py-8">
      <slot />
    </main>
  </div>
</template>
