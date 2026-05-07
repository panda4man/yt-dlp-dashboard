<script setup>
import { Link } from '@inertiajs/vue3'
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <nav class="bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-between">
      <div class="flex items-center gap-6">
        <span class="font-semibold text-gray-800">yt-dlp Dashboard</span>
        <Link href="/" class="text-sm text-gray-600 hover:text-gray-900">Dashboard</Link>
        <Link href="/history" class="text-sm text-gray-600 hover:text-gray-900">History</Link>
      </div>
      <Link
        href="/logout"
        method="post"
        as="button"
        class="text-sm text-gray-500 hover:text-gray-800"
      >
        Sign out
      </Link>
    </nav>

    <main class="max-w-5xl mx-auto px-6 py-8">
      <slot />
    </main>
  </div>
</template>
