# Plex Metadata — Design Spec
_Date: 2026-05-07_

## Context

Videos exported to Unraid/Plex currently land as unstructured flat files with generic names. Plex can't auto-detect them as a TV show library or populate metadata (title, description, premiere date). This adds proper Plex TV Show support: each YouTube channel becomes a Plex show, each video becomes an episode filed under `Season {YYYY}`. Kodi-compatible NFO sidecar files carry the metadata so Plex doesn't need internet access or API calls.

---

## New Metadata Fields

Three new columns added to the `downloads` table. All three are already returned by `yt-dlp --dump-json` (used in `YtDlpService::getMetadata()`) — they just aren't currently mapped.

| Column | Type | yt-dlp source key |
|---|---|---|
| `youtube_video_id` | `string` | `id` |
| `uploaded_at` | `date`, nullable | `upload_date` (YYYYMMDD → Carbon date) |
| `description` | `text`, nullable | `description` |

`YtDlpService::getMetadata()` updated to return these three additional keys. `VideoController::store()` saves them to the `Download` record. `DownloadFactory` updated with sensible defaults.

---

## Plex Naming Helper — `App\Support\PlexNaming`

Single static helper used by both the NFO generator and the export service. Centralises all naming logic.

```php
PlexNaming::basename(Download $download): string
// Returns: "Veritasium - S2024E0315 - The Trillion Dollar Equation"
// (no extension — callers append .mp4 / .nfo / -thumb.jpg)

PlexNaming::sanitize(string $str): string
// Strips filesystem-illegal chars: / \ : * ? " < > |
// Collapses whitespace, trims

PlexNaming::season(Download $download): int
// Returns: upload year (e.g. 2024), or created_at year as fallback

PlexNaming::episode(Download $download): string
// Returns: MMDD string (e.g. "0315"), with "b"/"c" suffix on same-channel same-day collision
// Collision detection: queries DB for completed downloads only (excludes pending/failed) with same channel + uploaded_at
// Suffix is filesystem-only — NFO <episode> tag always uses the plain integer (315), never "315b"
```

---

## NFO Generation — `App\Services\PlexNfoService`

Generates the two NFO file types.

### `episodeNfo(Download $download): string`
Returns XML string:
```xml
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<episodedetails>
  <title>{title}</title>
  <showtitle>{channel}</showtitle>
  <season>{YYYY}</season>
  <episode>{MMDD}</episode>
  <plot>{description}</plot>
  <premiered>{YYYY-MM-DD}</premiered>
  <studio>{channel}</studio>
  <thumb>thumbnail.jpg</thumb>
  <uniqueid type="youtube">{youtube_video_id}</uniqueid>
</episodedetails>
```

### `showNfo(Download $download): string`
Returns XML string:
```xml
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<tvshow>
  <title>{channel}</title>
</tvshow>
```

---

## ProcessDownload Job Changes

After `thumbnail.jpg` is generated, call `PlexNfoService::episodeNfo()` and write the result to `storage/app/private/downloads/{id}/episode.nfo`. The job already has the `Download` record with all needed fields.

No new process calls. Pure PHP file write.

---

## ExportService Changes

Current: rsyncs entire `downloads/{id}/` flat to `{RSYNC_DEST_PATH}/`.

New behaviour:

1. **Compute Plex basename**: `PlexNaming::basename($download)`
2. **Compute remote paths**:
   - Show dir: `{RSYNC_DEST_PATH}/{sanitized_channel}/`
   - Season dir: `{RSYNC_DEST_PATH}/{sanitized_channel}/Season {YYYY}/`
3. **Create remote directories** via SSH:
   ```
   ssh -i {key} -o StrictHostKeyChecking=no {user}@{host} "mkdir -p '{season_dir}'"
   ```
4. **Rsync 3 episode files** with explicit remote filenames:
   - `video.mp4` → `{season_dir}/{basename}.mp4`
   - `episode.nfo` → `{season_dir}/{basename}.nfo`
   - `thumbnail.jpg` → `{season_dir}/{basename}-thumb.jpg`
5. **Rsync tvshow.nfo** to show root (generated fresh, safe to overwrite):
   - `{show_dir}/tvshow.nfo`

Each rsync call uses the same SSH key config as today. All five operations use Process facade with array args (no shell injection).

---

## File Map

| File | Change |
|---|---|
| `database/migrations/xxxx_add_plex_metadata_to_downloads.php` | New migration — 3 columns |
| `app/Models/Download.php` | Add 3 fillable fields + `uploaded_at` cast |
| `app/Services/YtDlpService.php` | Return `id`, `upload_date`, `description` from `getMetadata()` |
| `app/Http/Controllers/VideoController.php` | Save new fields in `store()` |
| `app/Support/PlexNaming.php` | **New** — `basename()`, `sanitize()`, `season()`, `episode()` |
| `app/Services/PlexNfoService.php` | **New** — `episodeNfo()`, `showNfo()` |
| `app/Jobs/ProcessDownload.php` | Write `episode.nfo` after thumbnail generation |
| `app/Services/ExportService.php` | Structured rsync to Plex path |
| `database/factories/DownloadFactory.php` | Add defaults for new fields |

---

## Edge Cases

- **No `uploaded_at`**: If yt-dlp doesn't return `upload_date` (rare), fall back to `created_at` for season/episode. NFO `<premiered>` omitted.
- **Same-channel same-day collision**: `PlexNaming::episode()` queries DB and appends `b`, `c`... suffix.
- **Special chars in channel/title**: `PlexNaming::sanitize()` strips all Plex/filesystem-illegal characters before use in filenames and remote paths.
- **Remote mkdir failure**: Treat as fatal — throw exception, export fails. User retries.

---

## Verification

1. Queue and complete a download — confirm `episode.nfo` exists in `storage/app/private/downloads/{id}/` with correct XML
2. Confirm `youtube_video_id`, `uploaded_at`, `description` populated on the Download record
3. Trigger export — confirm remote directory structure `{channel}/Season {YYYY}/` created
4. Confirm all 3 episode files land with Plex-correct names
5. Confirm `tvshow.nfo` exists at show root
6. On Plex: add Unraid path as TV Shows library → channel appears as show, video as episode with metadata
