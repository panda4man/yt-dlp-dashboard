# yt-dlp Dashboard — Design Spec
_Date: 2026-05-06_

## Context

Internal tool for downloading YouTube videos via yt-dlp, generating Plex-compatible square thumbnails, tracking download history with metrics, and exporting completed downloads to a local Unraid/Plex server. Built as a Laravel + Inertia + Vue 3 SPA. No public access — single admin user, Docker-deployed.

---

## Stack

| Layer | Choice |
|---|---|
| Backend | Laravel 12 (PHP 8.4) |
| Frontend | Inertia.js + Vue 3 + Tailwind CSS |
| Queue | Laravel Horizon + Redis |
| Database | MySQL 8 |
| Containerization | Docker Compose |
| Downloader | yt-dlp (baked into Docker image) |
| Thumbnail | ffmpeg (baked into Docker image) |

---

## Architecture

```
Browser (Vue/Inertia SPA)
    ↕ Inertia requests (HTTP)
Laravel 12 (PHP-FPM + nginx)
    ├── Auth — session-based, login-only, no register
    ├── VideoController — preview fetch, queue submission
    ├── DownloadController — history listing, export trigger, delete
    └── Jobs/ProcessDownload — yt-dlp + ffmpeg pipeline
            ↕ Redis
        Laravel Horizon (queue worker)
```

Docker services: `app`, `horizon` (same image, different CMD), `redis`, `mysql`.

Frontend polling interval: **5 seconds** for active queue status.

---

## Data Model

Table: `downloads`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `youtube_url` | string | original user input |
| `title` | string | from yt-dlp metadata |
| `channel` | string | from yt-dlp metadata |
| `duration_seconds` | int | from yt-dlp metadata |
| `status` | enum | `pending`, `processing`, `completed`, `failed` |
| `file_path` | string nullable | relative to storage root |
| `thumbnail_path` | string nullable | square-cropped jpg, relative to storage root |
| `file_size_bytes` | bigint nullable | set on completion |
| `download_speed_bps` | bigint nullable | avg bytes/sec parsed from yt-dlp stdout |
| `started_at` | timestamp nullable | when job begins processing |
| `completed_at` | timestamp nullable | when job finishes successfully |
| `exported_at` | timestamp nullable | set when export action runs |
| `error_message` | text nullable | stderr on failure |
| `created_at` / `updated_at` | timestamps | |

---

## Job Processing — `ProcessDownload`

Runs on Redis queue via Horizon. Steps in order:

1. Set status → `processing`, set `started_at`
2. Run `yt-dlp` — output to `storage/app/private/downloads/{id}/video.mp4`
   - Parse stdout for average download speed → `download_speed_bps`
3. Run `ffmpeg` — center-crop video thumbnail to square
   - Take `min(width, height)` as crop size, center on both axes
   - Output: `storage/app/private/downloads/{id}/thumbnail.jpg`
4. Stat the output file → `file_size_bytes`
5. Set `completed_at`, status → `completed`
6. On any exception → status `failed`, store stderr in `error_message`

Both `yt-dlp` and `ffmpeg` invoked via Laravel `Process` facade with **array args** (no shell string interpolation — prevents injection).

---

## Duplicate Submission Validation

Check on `youtube_url` exact match before queuing:

- **Status `pending` or `processing`** → return 422 validation error: "This video is already in the queue."
- **Status `completed`** → return 409 with `{ already_downloaded: true }`. Vue shows confirmation modal: "Already downloaded. Download again?" On confirm, re-submit with `force: true` which bypasses the completed check.

---

## Pages & UI

**Login** (`/login`) — Email + password form. No register link. Redirects to `/` on success.

**Dashboard** (`/`) — Protected.
- URL input bar + "Preview" button
- Preview card: thumbnail, title, channel, formatted duration
  - "Add to Queue" button
- Active queue table (pending + processing rows), polling every 5s
  - Columns: title, channel, status badge, queued-at

**History** (`/history`) — Protected.
- Table of all downloads ordered by `created_at` desc
- Columns: thumbnail, title, channel, duration, file size, avg speed, downloaded-at, status badge, exported-at
- Per-row actions:
  - **Export** button — triggers export job (rsync to Unraid); disabled + strikethrough if `exported_at` set
  - **Delete** button — deletes file from disk + removes DB record; confirm dialog

UI style: minimal Tailwind, light mode only, no animations. Internal tool aesthetic.

---

## Export (Rsync)

Triggered manually per completed download from History page.

Configured entirely via `.env`:
```
RSYNC_HOST=
RSYNC_USER=
RSYNC_DEST_PATH=
RSYNC_SSH_KEY_PATH=
```

Runs `rsync -avz -e "ssh -i {key} -o StrictHostKeyChecking=no" {local_path} {user}@{host}:{dest}` via `Process` facade (array args). Sets `exported_at` on success.

---

## Auth

- Session-based Laravel auth
- Login page only — no register route
- Admin user created via artisan command:
  ```
  php artisan admin:create {email} {password}
  ```
- All non-login routes protected by `auth` middleware

---

## Docker

**`Dockerfile`** (single image, used by both `app` and `horizon` services):
- Base: `php:8.4-fpm`
- Installs: nginx, ffmpeg, yt-dlp, PHP extensions (`pdo_mysql`, `redis`, `pcntl`, `zip`, `exif`, `gd`)
- Composer install
- Vite build baked in

**`docker-compose.yml`** services:

| Service | Image | Notes |
|---|---|---|
| `app` | custom | PHP-FPM + nginx, port 80 |
| `horizon` | custom | same image, CMD: `php artisan horizon` |
| `redis` | `redis:alpine` | internal only |
| `mysql` | `mysql:8` | port 3306 exposed to host for debugging |

**Volume:** `./storage/app/private/downloads:/var/www/html/storage/app/private/downloads`

**`.env.example`** ships with all required keys documented.

---

## Artisan Commands

| Command | Purpose |
|---|---|
| `php artisan admin:create {email} {password}` | Create/reset admin user |

---

## Verification

1. `docker compose up --build` — all 4 services healthy
2. `php artisan admin:create admin@example.com secret` — user created
3. Navigate to `http://localhost` — redirected to login
4. Login → Dashboard loads
5. Paste YouTube URL → preview card appears with correct title/channel/duration
6. Add to Queue → row appears in active queue table; Horizon processes it; status cycles pending → processing → completed
7. Check `storage/app/private/downloads/{id}/` — `video.mp4` and `thumbnail.jpg` present; thumbnail is square
8. History page → completed row visible with file size and speed populated
9. Export button → `exported_at` set, button disabled
10. Delete button → file removed from disk, row removed from table
11. Submit same URL again → validation error if queued; confirm modal if completed
