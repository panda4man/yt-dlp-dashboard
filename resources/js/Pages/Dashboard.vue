<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'

const url = ref('')
const preview = ref(null)
const previewError = ref('')
const isLoadingPreview = ref(false)
const isSubmitting = ref(false)
const submitError = ref('')
const showForceConfirm = ref(false)
const queue = ref([])

async function fetchPreview() {
  if (!url.value) return
  previewError.value = ''
  preview.value = null
  isLoadingPreview.value = true

  try {
    const res = await fetch('/videos/preview', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
      },
      body: JSON.stringify({ url: url.value }),
    })
    const data = await res.json()
    if (!res.ok) {
      previewError.value = data.errors?.url?.[0] ?? 'Failed to fetch preview.'
    } else {
      preview.value = data
    }
  } catch {
    previewError.value = 'Network error.'
  } finally {
    isLoadingPreview.value = false
  }
}

async function addToQueue(force = false) {
  isSubmitting.value = true
  submitError.value = ''

  try {
    const res = await fetch('/videos', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
      },
      body: JSON.stringify({ url: url.value, force }),
    })

    if (res.status === 409) {
      showForceConfirm.value = true
    } else if (!res.ok) {
      const data = await res.json()
      submitError.value = data.errors?.url?.[0] ?? 'Could not add to queue.'
    } else {
      url.value = ''
      preview.value = null
      showForceConfirm.value = false
      await pollQueue()
    }
  } finally {
    isSubmitting.value = false
  }
}

async function pollQueue() {
  const res = await fetch('/api/queue')
  if (res.ok) queue.value = await res.json()
}

function formatDuration(seconds) {
  const m = Math.floor(seconds / 60)
  const s = seconds % 60
  return `${m}:${String(s).padStart(2, '0')}`
}

let interval
onMounted(() => {
  pollQueue()
  interval = setInterval(pollQueue, 5000)
})
onUnmounted(() => clearInterval(interval))
</script>

<template>
  <AppLayout>
    <!-- URL Input -->
    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-6 mb-6">
      <h2 class="text-lg font-medium text-gray-800 dark:text-gray-100 mb-4">Add Video</h2>
      <div class="flex gap-2">
        <input
          v-model="url"
          type="url"
          placeholder="https://youtube.com/watch?v=..."
          class="flex-1 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:placeholder-gray-400 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
          @keydown.enter="fetchPreview"
        />
        <button
          @click="fetchPreview"
          :disabled="isLoadingPreview || !url"
          class="bg-gray-800 dark:bg-gray-600 text-white rounded px-4 py-2 text-sm font-medium hover:bg-gray-900 dark:hover:bg-gray-500 disabled:opacity-50"
        >
          {{ isLoadingPreview ? 'Loading…' : 'Preview' }}
        </button>
      </div>
      <p v-if="previewError" class="text-red-600 dark:text-red-400 text-sm mt-2">{{ previewError }}</p>
    </div>

    <!-- Preview Card -->
    <div v-if="preview" class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-6 mb-6 flex gap-4">
      <img :src="preview.thumbnail" alt="thumbnail" class="w-32 h-32 object-cover rounded" />
      <div class="flex-1">
        <h3 class="font-medium text-gray-900 dark:text-gray-100">{{ preview.title }}</h3>
        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ preview.channel }}</p>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ formatDuration(preview.duration) }}</p>
        <p v-if="submitError" class="text-red-600 dark:text-red-400 text-sm mt-2">{{ submitError }}</p>
        <button
          @click="addToQueue(false)"
          :disabled="isSubmitting"
          class="mt-3 bg-blue-600 text-white rounded px-4 py-2 text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
        >
          Add to Queue
        </button>
      </div>
    </div>

    <!-- Force Confirm Modal -->
    <div v-if="showForceConfirm" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
      <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-sm shadow-xl">
        <h3 class="font-medium text-gray-900 dark:text-gray-100 mb-2">Already downloaded</h3>
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">This video has already been downloaded. Download again?</p>
        <div class="flex gap-2 justify-end">
          <button @click="showForceConfirm = false" class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded">Cancel</button>
          <button @click="addToQueue(true)" class="px-4 py-2 text-sm bg-blue-600 text-white rounded hover:bg-blue-700">Download Again</button>
        </div>
      </div>
    </div>

    <!-- Active Queue -->
    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
      <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700">
        <h2 class="text-lg font-medium text-gray-800 dark:text-gray-100">Queue</h2>
      </div>
      <div v-if="queue.length === 0" class="px-6 py-8 text-center text-sm text-gray-400 dark:text-gray-500">
        Queue is empty.
      </div>
      <table v-else class="w-full text-sm">
        <thead class="bg-gray-50 dark:bg-gray-700 text-left text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">
          <tr>
            <th class="px-6 py-3">Title</th>
            <th class="px-6 py-3">Channel</th>
            <th class="px-6 py-3">Duration</th>
            <th class="px-6 py-3">Status</th>
            <th class="px-6 py-3">Added</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
          <tr v-for="item in queue" :key="item.id">
            <td class="px-6 py-4 font-medium text-gray-900 dark:text-gray-100 max-w-xs truncate">{{ item.title }}</td>
            <td class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ item.channel }}</td>
            <td class="px-6 py-4 text-gray-500 dark:text-gray-400">{{ formatDuration(item.duration_seconds) }}</td>
            <td class="px-6 py-4">
              <span
                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                :class="{
                  'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200': item.status === 'pending',
                  'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200': item.status === 'processing',
                }"
              >{{ item.status }}</span>
            </td>
            <td class="px-6 py-4 text-gray-400 dark:text-gray-500">{{ new Date(item.created_at).toLocaleString() }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </AppLayout>
</template>
