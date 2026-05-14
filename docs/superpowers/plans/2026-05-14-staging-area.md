# Staging Area Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Insert a mandatory staging step between download completion and export, where users can correct channel/title/upload date/description and approve the NFO before export unlocks.

**Architecture:** Add `staged` to the `DownloadStatus` enum. `ProcessDownload` job sets final status to `Staged` instead of `Completed`. A new `StagingController` serves the Staging page and handles save-draft / approve actions; approve regenerates the NFO on disk and flips status to `Completed`, unlocking export. The History page excludes staged items.

**Tech Stack:** Laravel 12, Inertia.js, Vue 3, Tailwind CSS v4, MySQL 8

---

### Task 1: Migration — add `staged` to the downloads status enum

**Files:**
- Create: `database/migrations/2026_05_14_000001_add_staged_to_downloads_status.php`

- [ ] **Step 1: Create the migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE downloads MODIFY COLUMN status ENUM('pending','processing','staged','completed','failed') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE downloads MODIFY COLUMN status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending'");
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate
```

Expected output: `Migrating: 2026_05_14_000001_add_staged_to_downloads_status` then `Migrated`.

- [ ] **Step 3: Verify the enum column**

```bash
php artisan tinker --execute="DB::select('DESCRIBE downloads')[5]" | php -r "echo json_encode(json_decode(file_get_contents('php://stdin')), JSON_PRETTY_PRINT);"
```

Expected: `status` field shows `enum('pending','processing','staged','completed','failed')`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_05_14_000001_add_staged_to_downloads_status.php
git commit -m "feat: add staged to downloads status enum"
```

---

### Task 2: Enum + Job — add Staged case, update ProcessDownload

**Files:**
- Modify: `app/Enums/DownloadStatus.php`
- Modify: `app/Jobs/ProcessDownload.php`

- [ ] **Step 1: Add `Staged` to the enum**

Replace the full content of `app/Enums/DownloadStatus.php`:

```php
<?php

namespace App\Enums;

enum DownloadStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Staged     = 'staged';
    case Completed  = 'completed';
    case Failed     = 'failed';
}
```

- [ ] **Step 2: Update ProcessDownload to set status Staged on success**

In `app/Jobs/ProcessDownload.php`, change the final `update()` call (line 43) from `DownloadStatus::Completed` to `DownloadStatus::Staged`:

```php
$this->download->update([
    'status'             => DownloadStatus::Staged,
    'file_path'          => 'downloads/' . $this->download->id . '/video.mp4',
    'thumbnail_path'     => 'downloads/' . $this->download->id . '/thumbnail.jpg',
    'file_size_bytes'    => $fileSize,
    'download_speed_bps' => (int) ($fileSize / $elapsed),
    'completed_at'       => $completedAt,
]);
```

- [ ] **Step 3: Verify enum is used correctly**

```bash
php artisan tinker --execute="echo App\Enums\DownloadStatus::Staged->value;"
```

Expected: `staged`

- [ ] **Step 4: Commit**

```bash
git add app/Enums/DownloadStatus.php app/Jobs/ProcessDownload.php
git commit -m "feat: route completed downloads to staged status"
```

---

### Task 3: StagingController — index, update, approve

**Files:**
- Create: `app/Http/Controllers/StagingController.php`

- [ ] **Step 1: Create the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Enums\DownloadStatus;
use App\Models\Download;
use App\Services\PlexNfoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class StagingController extends Controller
{
    public function index(): Response
    {
        $downloads = Download::where('status', DownloadStatus::Staged)
            ->orderByDesc('created_at')
            ->get([
                'id', 'title', 'channel', 'duration_seconds',
                'thumbnail_path', 'uploaded_at', 'description', 'status', 'created_at',
            ]);

        return Inertia::render('Staging', ['downloads' => $downloads]);
    }

    public function update(Request $request, Download $download): JsonResponse
    {
        $data = $request->validate([
            'channel'     => ['required', 'string', 'max:255'],
            'title'       => ['required', 'string', 'max:255'],
            'uploaded_at' => ['required', 'date'],
            'description' => ['nullable', 'string'],
        ]);

        $download->update($data);
        $this->regenerateNfo($download); // calls $download->refresh() internally

        return response()->json($download);
    }

    public function approve(Request $request, Download $download): JsonResponse
    {
        $data = $request->validate([
            'channel'     => ['required', 'string', 'max:255'],
            'title'       => ['required', 'string', 'max:255'],
            'uploaded_at' => ['required', 'date'],
            'description' => ['nullable', 'string'],
        ]);

        $download->update([...$data, 'status' => DownloadStatus::Completed]);
        $this->regenerateNfo($download);

        return response()->json(['approved' => true]);
    }

    private function regenerateNfo(Download $download): void
    {
        $download->refresh();
        $nfo  = app(PlexNfoService::class)->episodeNfo($download);
        $path = 'downloads/' . $download->id . '/episode.nfo';
        Storage::disk('local')->put($path, $nfo);
    }
}
```

- [ ] **Step 2: Verify the file parses without error**

```bash
php artisan tinker --execute="new App\Http\Controllers\StagingController; echo 'ok';"
```

Expected: `ok`

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/StagingController.php
git commit -m "feat: add StagingController with index, update, approve"
```

---

### Task 4: Routes, DownloadController filter, shared stagedCount prop

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/DownloadController.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`

- [ ] **Step 1: Add staging routes to `routes/web.php`**

Add inside the `Route::middleware('auth')->group` block, after the history routes:

```php
use App\Http\Controllers\StagingController;

Route::get('/staging', [StagingController::class, 'index'])->name('staging');
Route::put('/staging/{download}', [StagingController::class, 'update']);
Route::post('/staging/{download}/approve', [StagingController::class, 'approve']);
```

Also add the `StagingController` use statement at the top of the file with the other imports.

The full updated `routes/web.php`:

```php
<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\StagingController;
use App\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', [VideoController::class, 'index'])->name('dashboard');
    Route::post('/videos/preview', [VideoController::class, 'preview']);
    Route::post('/videos', [VideoController::class, 'store']);
    Route::get('/api/queue', [VideoController::class, 'queue']);

    Route::get('/history', [DownloadController::class, 'index'])->name('history');
    Route::get('/downloads/{download}/thumbnail', [DownloadController::class, 'thumbnail']);
    Route::delete('/downloads/{download}', [DownloadController::class, 'destroy']);

    Route::post('/downloads/{download}/export', [ExportController::class, 'store']);

    Route::get('/staging', [StagingController::class, 'index'])->name('staging');
    Route::put('/staging/{download}', [StagingController::class, 'update']);
    Route::post('/staging/{download}/approve', [StagingController::class, 'approve']);
});
```

- [ ] **Step 2: Exclude staged from History in `DownloadController::index()`**

Replace the `index()` method in `app/Http/Controllers/DownloadController.php`:

```php
public function index(): Response
{
    $downloads = Download::where('status', '!=', DownloadStatus::Staged->value)
        ->orderByDesc('created_at')
        ->get([
            'id', 'title', 'channel', 'duration_seconds', 'thumbnail_path',
            'status', 'file_size_bytes', 'download_speed_bps',
            'completed_at', 'exported_at', 'error_message', 'created_at',
        ]);

    return Inertia::render('History', ['downloads' => $downloads]);
}
```

Add the missing import at the top of `DownloadController.php`:

```php
use App\Enums\DownloadStatus;
```

- [ ] **Step 3: Share `stagedCount` in `HandleInertiaRequests`**

Replace the `share()` method in `app/Http/Middleware/HandleInertiaRequests.php`:

```php
public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'stagedCount' => fn () => $request->user()
            ? \App\Models\Download::where('status', \App\Enums\DownloadStatus::Staged)->count()
            : 0,
    ];
}
```

- [ ] **Step 4: Verify routes load**

```bash
php artisan route:list --path=staging
```

Expected: three routes (`GET /staging`, `PUT /staging/{download}`, `POST /staging/{download}/approve`).

- [ ] **Step 5: Commit**

```bash
git add routes/web.php app/Http/Controllers/DownloadController.php app/Http/Middleware/HandleInertiaRequests.php
git commit -m "feat: add staging routes, exclude staged from history, share stagedCount"
```

---

### Task 5: AppLayout.vue — Staging nav link with badge

**Files:**
- Modify: `resources/js/Layouts/AppLayout.vue`

- [ ] **Step 1: Update AppLayout to add Staging link and consume `stagedCount` shared prop**

Replace the full content of `resources/js/Layouts/AppLayout.vue`:

```vue
<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useTheme } from '@/composables/useTheme'

const { isDark, toggle } = useTheme()
const stagedCount = computed(() => usePage().props.stagedCount ?? 0)
</script>

<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-900">
    <nav class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-6 py-3 flex items-center justify-between">
      <div class="flex items-center gap-6">
        <span class="font-semibold text-gray-800 dark:text-gray-100">yt-dlp Dashboard</span>
        <Link href="/" class="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">Dashboard</Link>
        <Link href="/staging" class="relative text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 flex items-center gap-1.5">
          Staging
          <span
            v-if="stagedCount > 0"
            class="inline-flex items-center justify-center h-4 min-w-4 px-1 rounded-full text-xs font-medium bg-indigo-600 text-white"
          >{{ stagedCount }}</span>
        </Link>
        <Link href="/history" class="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">History</Link>
      </div>
      <div class="flex items-center gap-4">
        <button
          @click="toggle"
          :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
          class="p-1 rounded text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-100"
        >
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
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Layouts/AppLayout.vue
git commit -m "feat: add Staging nav link with count badge"
```

---

### Task 6: Staging.vue — staging list + edit modal

**Files:**
- Create: `resources/js/Pages/Staging.vue`

- [ ] **Step 1: Create the Staging page**

```vue
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
```

- [ ] **Step 2: Build frontend assets**

```bash
npm run build
```

Expected: no errors, assets compiled.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Staging.vue
git commit -m "feat: add Staging page with edit modal and Plex preview"
```

---

### Task 7: End-to-end verification

- [ ] **Step 1: Add a YouTube URL to the queue and wait for download to complete**

Confirm the item appears on `/staging` (not `/history`). Nav badge shows count > 0.

- [ ] **Step 2: Open the edit modal**

Click Edit on the staged item. Confirm all four fields are pre-populated. Type in the Channel field and confirm the Plex filename preview updates live.

- [ ] **Step 3: Test Save Draft**

Change the channel name, click Save Draft. Confirm modal closes, row in table shows updated channel, item still on Staging page.

- [ ] **Step 4: Test Approve**

Open Edit again, click Approve for Export. Confirm item disappears from Staging, nav badge decrements. Navigate to History — item appears with status `completed` and Export button enabled.

- [ ] **Step 5: Verify NFO on disk**

```bash
cat storage/app/private/downloads/<id>/episode.nfo
```

Confirm `<showtitle>` and `<title>` match the approved values.

- [ ] **Step 6: Export and confirm rsync picks up the updated NFO**

Click Export on the History item. Confirm it completes without error.
