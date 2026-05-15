<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  waiting:   Array,
  exporting: Array,
  failed:    Array,
  recent:    Array,
})

const waiting   = ref([...props.waiting])
const exporting = ref([...props.exporting])
const failed    = ref([...props.failed])
const recent    = ref([...props.recent])

const actionError = ref('')

function csrfToken() {
  return document.querySelector('meta[name=csrf-token]')?.content ?? ''
}

function formatBytes(bytes) {
  if (!bytes) return '—'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

async function pollQueue() {
  try {
    const res = await fetch('/api/export-queue')
    if (!res.ok) return
    const data = await res.json()
    waiting.value   = data.waiting
    exporting.value = data.exporting
    failed.value    = data.failed
    recent.value    = data.recent
  } catch {
    // silent — next poll will retry
  }
}

async function triggerExport(id) {
  actionError.value = ''

  // Optimistic: move item from waiting/failed to exporting
  const fromWaiting = waiting.value.findIndex(i => i.id === id)
  const fromFailed  = failed.value.findIndex(i => i.id === id)
  let item = null

  if (fromWaiting !== -1) {
    item = { ...waiting.value[fromWaiting], status: 'exporting' }
    waiting.value.splice(fromWaiting, 1)
  } else if (fromFailed !== -1) {
    item = { ...failed.value[fromFailed], status: 'exporting' }
    failed.value.splice(fromFailed, 1)
  }

  if (item) exporting.value.push(item)

  try {
    const res = await fetch(`/downloads/${id}/export`, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
    })
    if (!res.ok) {
      const data = await res.json()
      actionError.value = data.message ?? 'Export failed to start.'
      await pollQueue()
    }
  } catch {
    actionError.value = 'Network error.'
    await pollQueue()
  }
}

let interval
onMounted(() => {
  interval = setInterval(pollQueue, 5000)
})
onUnmounted(() => clearInterval(interval))
</script>

<template>
  <AppLayout>
    <div class="space-y-6">
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-800 dark:text-gray-100">Export Queue</h2>
      </div>

      <p v-if="actionError" class="text-sm text-red-600 dark:text-red-400">{{ actionError }}</p>

      <!-- Empty state -->
      <div
        v-if="!failed.length && !exporting.length && !waiting.length && !recent.length"
        class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 px-6 py-12 text-center text-sm text-gray-400 dark:text-gray-500"
      >
        Nothing in the export queue. Approve videos in Staging to queue them for export.
      </div>

      <!-- Failed -->
      <div v-if="failed.length" class="bg-white dark:bg-gray-800 rounded-lg border border-red-200 dark:border-red-800">
        <div class="px-6 py-3 border-b border-red-100 dark:border-red-800 flex items-center gap-2">
          <span class="inline-block w-2 h-2 rounded-full bg-red-500"></span>
          <span class="text-sm font-medium text-red-700 dark:text-red-300">Failed ({{ failed.length }})</span>
        </div>
        <ul class="divide-y divide-gray-100 dark:divide-gray-700">
          <li v-for="item in failed" :key="item.id" class="px-6 py-4 flex items-center gap-4">
            <img
              v-if="item.thumbnail_path"
              :src="`/downloads/${item.id}/thumbnail`"
              class="w-12 h-12 object-cover rounded flex-shrink-0"
              alt=""
            />
            <div v-else class="w-12 h-12 bg-gray-100 dark:bg-gray-700 rounded flex-shrink-0" />
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ item.title }}</p>
              <p class="text-xs text-gray-500 dark:text-gray-400">{{ item.channel }} · {{ formatBytes(item.file_size_bytes) }}</p>
              <p v-if="item.export_error" class="text-xs text-red-600 dark:text-red-400 mt-1 truncate" :title="item.export_error">{{ item.export_error }}</p>
            </div>
            <button
              @click="triggerExport(item.id)"
              class="flex-shrink-0 text-xs px-3 py-1.5 rounded bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 hover:bg-red-100 dark:hover:bg-red-900/50 font-medium"
            >
              Retry
            </button>
          </li>
        </ul>
      </div>

      <!-- Exporting -->
      <div v-if="exporting.length" class="bg-white dark:bg-gray-800 rounded-lg border border-blue-200 dark:border-blue-800">
        <div class="px-6 py-3 border-b border-blue-100 dark:border-blue-800 flex items-center gap-2">
          <svg class="w-3 h-3 text-blue-500 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
          </svg>
          <span class="text-sm font-medium text-blue-700 dark:text-blue-300">Exporting ({{ exporting.length }})</span>
        </div>
        <ul class="divide-y divide-gray-100 dark:divide-gray-700">
          <li v-for="item in exporting" :key="item.id" class="px-6 py-4 flex items-center gap-4">
            <img
              v-if="item.thumbnail_path"
              :src="`/downloads/${item.id}/thumbnail`"
              class="w-12 h-12 object-cover rounded flex-shrink-0"
              alt=""
            />
            <div v-else class="w-12 h-12 bg-gray-100 dark:bg-gray-700 rounded flex-shrink-0" />
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ item.title }}</p>
              <p class="text-xs text-gray-500 dark:text-gray-400">{{ item.channel }} · {{ formatBytes(item.file_size_bytes) }}</p>
            </div>
            <span class="flex-shrink-0 text-xs text-blue-500 dark:text-blue-400">Exporting…</span>
          </li>
        </ul>
      </div>

      <!-- Waiting -->
      <div v-if="waiting.length" class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
        <div class="px-6 py-3 border-b border-gray-100 dark:border-gray-700">
          <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Waiting to Export ({{ waiting.length }})</span>
        </div>
        <ul class="divide-y divide-gray-100 dark:divide-gray-700">
          <li v-for="item in waiting" :key="item.id" class="px-6 py-4 flex items-center gap-4">
            <img
              v-if="item.thumbnail_path"
              :src="`/downloads/${item.id}/thumbnail`"
              class="w-12 h-12 object-cover rounded flex-shrink-0"
              alt=""
            />
            <div v-else class="w-12 h-12 bg-gray-100 dark:bg-gray-700 rounded flex-shrink-0" />
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ item.title }}</p>
              <p class="text-xs text-gray-500 dark:text-gray-400">{{ item.channel }} · {{ formatBytes(item.file_size_bytes) }}</p>
            </div>
            <button
              @click="triggerExport(item.id)"
              class="flex-shrink-0 text-xs px-3 py-1.5 rounded bg-indigo-600 text-white hover:bg-indigo-700 font-medium"
            >
              Export
            </button>
          </li>
        </ul>
      </div>

      <!-- Exported last 24h -->
      <div v-if="recent.length" class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
        <div class="px-6 py-3 border-b border-gray-100 dark:border-gray-700">
          <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Exported Last 24h ({{ recent.length }})</span>
        </div>
        <ul class="divide-y divide-gray-100 dark:divide-gray-700">
          <li v-for="item in recent" :key="item.id" class="px-6 py-4 flex items-center gap-4">
            <img
              v-if="item.thumbnail_path"
              :src="`/downloads/${item.id}/thumbnail`"
              class="w-12 h-12 object-cover rounded flex-shrink-0"
              alt=""
            />
            <div v-else class="w-12 h-12 bg-gray-100 dark:bg-gray-700 rounded flex-shrink-0" />
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ item.title }}</p>
              <p class="text-xs text-gray-500 dark:text-gray-400">{{ item.channel }} · {{ formatBytes(item.file_size_bytes) }}</p>
            </div>
            <div class="flex-shrink-0 flex items-center gap-1.5">
              <!-- Plex refresh succeeded -->
              <template v-if="item.plex_refreshed_at">
                <svg class="w-4 h-4 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="text-xs text-green-600 dark:text-green-400">Plex refreshed</span>
              </template>
              <!-- Plex refresh failed (soft warning) -->
              <template v-else-if="item.plex_error">
                <svg class="w-4 h-4 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                </svg>
                <span class="text-xs text-yellow-600 dark:text-yellow-400" :title="item.plex_error">Plex refresh failed</span>
              </template>
              <!-- Plex refresh pending -->
              <template v-else>
                <span class="text-xs text-gray-400 dark:text-gray-500">Plex pending</span>
              </template>
            </div>
          </li>
        </ul>
      </div>
    </div>
  </AppLayout>
</template>
