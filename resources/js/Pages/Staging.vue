<script setup>
import { ref, computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  downloads: Array,
})

const items = ref(props.downloads.map(d => ({ ...d })))

const editing = ref(null)
const form = ref({ channel: '', title: '', uploaded_at: '', description: '' })
const saving = ref(false)
const errorMsg = ref('')

function formatDuration(seconds) {
  const m = Math.floor(seconds / 60)
  const s = seconds % 60
  return `${m}:${String(s).padStart(2, '0')}`
}

function formatDate(dt) {
  if (!dt) return '—'
  return new Date(dt).toLocaleDateString()
}

function openEdit(item) {
  editing.value = item
  form.value = {
    channel:     item.channel,
    title:       item.title,
    uploaded_at: item.uploaded_at ? item.uploaded_at.substring(0, 10) : '',
    description: item.description ?? '',
  }
  errorMsg.value = ''
}

function closeModal() {
  editing.value = null
  errorMsg.value = ''
}

const plexPreview = computed(() => {
  const { channel, title, uploaded_at } = form.value
  if (!channel || !title || !uploaded_at) return ''
  const d   = new Date(uploaded_at + 'T00:00:00')
  const year = d.getFullYear()
  const mm   = String(d.getMonth() + 1).padStart(2, '0')
  const dd   = String(d.getDate()).padStart(2, '0')
  const sanitize = s => s.replace(/[/:*?"<>\\|]/g, '').replace(/\s+/g, ' ').trim()
  return `${sanitize(channel)} - S${year}E${mm}${dd} - ${sanitize(title)}`
})

function csrfToken() {
  return document.querySelector('meta[name=csrf-token]')?.content ?? ''
}

async function saveDraft() {
  if (!editing.value) return
  saving.value = true
  errorMsg.value = ''
  try {
    const res = await fetch(`/staging/${editing.value.id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
      body: JSON.stringify(form.value),
    })
    if (!res.ok) {
      const data = await res.json()
      errorMsg.value = Object.values(data.errors ?? {}).flat()[0] ?? 'Save failed.'
      return
    }
    const updated = await res.json()
    const idx = items.value.findIndex(i => i.id === editing.value.id)
    if (idx !== -1) items.value[idx] = { ...items.value[idx], ...updated }
    closeModal()
  } catch {
    errorMsg.value = 'Network error.'
  } finally {
    saving.value = false
  }
}

async function approve() {
  if (!editing.value) return
  saving.value = true
  errorMsg.value = ''
  try {
    const res = await fetch(`/staging/${editing.value.id}/approve`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
      body: JSON.stringify(form.value),
    })
    if (!res.ok) {
      const data = await res.json()
      errorMsg.value = Object.values(data.errors ?? {}).flat()[0] ?? 'Approve failed.'
      return
    }
    items.value = items.value.filter(i => i.id !== editing.value.id)
    closeModal()
  } catch {
    errorMsg.value = 'Network error.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <AppLayout>
    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
      <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700">
        <h2 class="text-lg font-medium text-gray-800 dark:text-gray-100">Staging</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Review and correct metadata before export.</p>
      </div>

      <div v-if="items.length === 0" class="px-6 py-8 text-center text-sm text-gray-400 dark:text-gray-500">
        No items awaiting review.
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 dark:bg-gray-700 text-left text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">
            <tr>
              <th class="px-4 py-3">Thumbnail</th>
              <th class="px-4 py-3">Title</th>
              <th class="px-4 py-3">Channel</th>
              <th class="px-4 py-3">Duration</th>
              <th class="px-4 py-3">Added</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
            <tr v-for="item in items" :key="item.id">
              <td class="px-4 py-3">
                <img
                  v-if="item.thumbnail_path"
                  :src="`/downloads/${item.id}/thumbnail`"
                  class="w-12 h-12 object-cover rounded"
                  alt=""
                />
                <div v-else class="w-12 h-12 bg-gray-100 dark:bg-gray-700 rounded" />
              </td>
              <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100 max-w-xs truncate">{{ item.title }}</td>
              <td class="px-4 py-3 text-gray-600 dark:text-gray-400">{{ item.channel }}</td>
              <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ formatDuration(item.duration_seconds) }}</td>
              <td class="px-4 py-3 text-gray-400 dark:text-gray-500 whitespace-nowrap">{{ formatDate(item.created_at) }}</td>
              <td class="px-4 py-3">
                <button
                  @click="openEdit(item)"
                  class="text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300"
                >Edit</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Edit Modal -->
    <Teleport to="body">
      <div
        v-if="editing"
        class="fixed inset-0 z-50 flex items-center justify-center"
        @click.self="closeModal"
      >
        <div class="absolute inset-0 bg-black/60" @click="closeModal" />
        <div class="relative bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-md mx-4 p-6 border border-gray-200 dark:border-gray-700">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Edit Metadata</h3>
            <button @click="closeModal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-xl leading-none">&times;</button>
          </div>

          <!-- Plex preview -->
          <div v-if="plexPreview" class="bg-gray-50 dark:bg-gray-900 rounded-lg px-3 py-2 mb-4 border border-gray-200 dark:border-gray-700">
            <div class="text-xs text-blue-500 font-medium mb-1">PLEX FILENAME PREVIEW</div>
            <div class="text-xs text-gray-600 dark:text-gray-400 font-mono truncate">{{ plexPreview }}</div>
          </div>

          <div v-if="errorMsg" class="text-sm text-red-600 dark:text-red-400 mb-3">{{ errorMsg }}</div>

          <div class="space-y-3">
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Channel / Show Name</label>
              <input
                v-model="form.channel"
                type="text"
                class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Title</label>
              <input
                v-model="form.title"
                type="text"
                class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">
                Upload Date <span class="text-gray-400 normal-case">(affects Plex season + episode)</span>
              </label>
              <input
                v-model="form.uploaded_at"
                type="date"
                class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Description</label>
              <textarea
                v-model="form.description"
                rows="3"
                class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"
              />
            </div>
          </div>

          <div class="flex gap-2 mt-5">
            <button
              @click="approve"
              :disabled="saving"
              class="flex-1 bg-green-600 hover:bg-green-700 disabled:opacity-50 text-white text-sm font-medium py-2 px-4 rounded-md"
            >Approve for Export</button>
            <button
              @click="saveDraft"
              :disabled="saving"
              class="bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 disabled:opacity-50 text-gray-700 dark:text-gray-200 text-sm py-2 px-4 rounded-md"
            >Save Draft</button>
            <button
              @click="closeModal"
              :disabled="saving"
              class="bg-transparent text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-sm py-2 px-3 rounded-md"
            >Cancel</button>
          </div>
        </div>
      </div>
    </Teleport>
  </AppLayout>
</template>
