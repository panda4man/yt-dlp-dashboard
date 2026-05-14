# Staging Area Design

## Context

Downloaded videos land in History immediately after processing. The channel name (used as the Plex show folder and NFO show title) often needs to be corrected before export — YouTube channels don't always map cleanly to Plex library organization. The user needs a mandatory review step between download completion and export, where they can correct channel, title, upload date, and description before the NFO is finalized and export is unlocked.

---

## Status Flow

```
pending → processing → staged → completed → (exported)
                                  ↑
                         user approves in staging
```

Add `staged` to the `DownloadStatus` enum. `ProcessDownload` job sets status to `staged` (not `completed`) on success. Export only unlocks at `completed`.

---

## Architecture

### Backend

**Migration:** Add `staged` to the `downloads.status` enum.

**Enum** (`app/Enums/DownloadStatus.php`): Add `Staged = 'staged'`.

**ProcessDownload job** (`app/Jobs/ProcessDownload.php`): Change final status from `Completed` to `Staged`.

**New controller** `app/Http/Controllers/StagingController.php`:
- `index()` — return `Staging` Inertia page with all `staged` downloads (select: id, title, channel, duration_seconds, thumbnail_path, uploaded_at, description, status, created_at)
- `update(Request $request, Download $download)` — validate + update title/channel/uploaded_at/description, regenerate NFO, keep status `staged`. Returns updated download.
- `approve(Request $request, Download $download)` — validate + update fields, regenerate NFO, set status → `Completed`. Returns updated download.

**NFO regeneration** (both update and approve): After saving DB fields, call `PlexNfoService::episodeNfo($download)` and write the result to `storage/app/private/downloads/{id}/episode.nfo` using `Storage::disk('local')->put(...)`.

**New routes** (`routes/web.php`):
```php
Route::get('/staging', [StagingController::class, 'index']);
Route::put('/staging/{download}', [StagingController::class, 'update']);
Route::post('/staging/{download}/approve', [StagingController::class, 'approve']);
```

**History exclusion** (`app/Http/Controllers/DownloadController.php`): Add `whereNotIn('status', [DownloadStatus::Staged])` to the index query so staged items don't appear in History.

### Frontend

**New page** `resources/js/Pages/Staging.vue`:
- Table of staged downloads: thumbnail, title, channel, duration, date added, Edit button
- Edit button opens modal
- Modal fields: Channel, Title, Upload Date (date input), Description (textarea)
- Live Plex filename preview (reactive, updates as user types):
  ```
  {channel} - S{year}E{MMDD} - {title}
  ```
  where year/MMDD come from the upload date field
- Three buttons: **Approve for Export** (POST approve), **Save Draft** (PUT update), **Cancel**
- On approve: remove item from staged list, show success toast
- On save draft: update item in list inline, close modal

**Nav update** (`resources/js/Layouts/AppLayout.vue`):
- Add "Staging" link between Queue and History
- Show count badge when `stagedCount > 0` (pass `stagedCount` as Inertia shared prop from `HandleInertiaRequests` middleware)

---

## Shared Prop for Badge

In `app/Http/Middleware/HandleInertiaRequests.php`, add to `share()`:
```php
'stagedCount' => fn () => auth()->check()
    ? Download::where('status', DownloadStatus::Staged)->count()
    : 0,
```

---

## Plex Filename Preview (JS)

Replicate `PlexNaming::basename()` in Vue:
```js
function plexPreview(channel, title, uploadDate) {
  if (!channel || !title || !uploadDate) return ''
  const d = new Date(uploadDate)
  const year = d.getFullYear()
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  const dd = String(d.getDate()).padStart(2, '0')
  const sanitize = s => s.replace(/[/:*?"<>\\|]/g, '').replace(/\s+/g, ' ').trim()
  return `${sanitize(channel)} - S${year}E${mm}${dd} - ${sanitize(title)}`
}
```

---

## Files Modified

| File | Change |
|---|---|
| `app/Enums/DownloadStatus.php` | Add `Staged = 'staged'` |
| `app/Jobs/ProcessDownload.php` | Final status → `Staged` |
| `app/Http/Controllers/StagingController.php` | **New** — index, update, approve |
| `app/Http/Controllers/DownloadController.php` | Exclude staged from history query |
| `app/Http/Middleware/HandleInertiaRequests.php` | Share `stagedCount` |
| `app/Services/PlexNfoService.php` | No change — reused as-is |
| `routes/web.php` | 3 new routes |
| `resources/js/Pages/Staging.vue` | **New** — staging list + edit modal |
| `resources/js/Layouts/AppLayout.vue` | Add Staging nav link + badge |
| `database/migrations/xxxx_add_staged_to_downloads_status.php` | **New** — alter enum |

---

## Validation Rules (StagingController)

```php
'channel'     => ['required', 'string', 'max:255'],
'title'       => ['required', 'string', 'max:255'],
'uploaded_at' => ['required', 'date'],
'description' => ['nullable', 'string'],
```

---

## Verification

1. Add a URL to the queue and confirm it downloads → status shows `staged` in Staging page (not in History)
2. Open edit modal — confirm all four fields pre-populated, Plex preview updates live as you type
3. Save Draft → modal closes, row updates inline, status stays `staged`, NFO on disk updated
4. Approve → item disappears from Staging, appears in History as `completed`, export button enabled
5. Export the item — confirm rsync uses the updated NFO
6. Nav badge shows correct count; drops to zero when all items approved
