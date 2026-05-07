<script setup>
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  downloads: Array,
})

function formatBytes(bytes) {
  if (!bytes) return '—'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

function formatSpeed(bps) {
  if (!bps) return '—'
  return (bps / (1024 * 1024)).toFixed(2) + ' MB/s'
}

function formatDuration(seconds) {
  const m = Math.floor(seconds / 60)
  const s = seconds % 60
  return `${m}:${String(s).padStart(2, '0')}`
}

function formatDate(dt) {
  if (!dt) return '—'
  return new Date(dt).toLocaleString()
}

function deleteDownload(id) {
  if (!confirm('Delete this download? Files will be removed.')) return
  router.delete(`/downloads/${id}`)
}

function exportDownload(id) {
  router.post(`/downloads/${id}/export`)
}
</script>

<template>
  <AppLayout>
    <div class="bg-white rounded-lg border border-gray-200">
      <div class="px-6 py-4 border-b border-gray-100">
        <h2 class="text-lg font-medium text-gray-800">Download History</h2>
      </div>

      <div v-if="downloads.length === 0" class="px-6 py-8 text-center text-sm text-gray-400">
        No downloads yet.
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-left text-xs text-gray-500 uppercase tracking-wide">
            <tr>
              <th class="px-4 py-3">Thumbnail</th>
              <th class="px-4 py-3">Title</th>
              <th class="px-4 py-3">Channel</th>
              <th class="px-4 py-3">Duration</th>
              <th class="px-4 py-3">Size</th>
              <th class="px-4 py-3">Speed</th>
              <th class="px-4 py-3">Downloaded</th>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="dl in downloads" :key="dl.id">
              <td class="px-4 py-3">
                <img
                  v-if="dl.thumbnail_path"
                  :src="`/downloads/${dl.id}/thumbnail`"
                  class="w-12 h-12 object-cover rounded"
                  alt=""
                />
                <div v-else class="w-12 h-12 bg-gray-100 rounded" />
              </td>
              <td class="px-4 py-3 font-medium text-gray-900 max-w-xs truncate">{{ dl.title }}</td>
              <td class="px-4 py-3 text-gray-600">{{ dl.channel }}</td>
              <td class="px-4 py-3 text-gray-500">{{ formatDuration(dl.duration_seconds) }}</td>
              <td class="px-4 py-3 text-gray-500">{{ formatBytes(dl.file_size_bytes) }}</td>
              <td class="px-4 py-3 text-gray-500">{{ formatSpeed(dl.download_speed_bps) }}</td>
              <td class="px-4 py-3 text-gray-400 whitespace-nowrap">{{ formatDate(dl.completed_at) }}</td>
              <td class="px-4 py-3">
                <span
                  class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                  :class="{
                    'bg-yellow-100 text-yellow-800': dl.status === 'pending',
                    'bg-blue-100 text-blue-800': dl.status === 'processing',
                    'bg-green-100 text-green-800': dl.status === 'completed',
                    'bg-red-100 text-red-800': dl.status === 'failed',
                  }"
                >{{ dl.status }}</span>
              </td>
              <td class="px-4 py-3 whitespace-nowrap">
                <button
                  @click="exportDownload(dl.id)"
                  :disabled="!!dl.exported_at || dl.status !== 'completed'"
                  class="text-xs text-blue-600 hover:text-blue-800 disabled:text-gray-300 disabled:cursor-not-allowed mr-3"
                >
                  {{ dl.exported_at ? 'Exported' : 'Export' }}
                </button>
                <button
                  @click="deleteDownload(dl.id)"
                  class="text-xs text-red-600 hover:text-red-800"
                >
                  Delete
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppLayout>
</template>
