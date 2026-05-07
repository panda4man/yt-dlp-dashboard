# yt-dlp Dashboard

Internal dashboard for downloading YouTube videos via yt-dlp, generating Plex-compatible square thumbnails, and exporting to Unraid.

## Stack

- PHP 8.4, Laravel 12, Inertia.js, Vue 3, Tailwind CSS v4
- Laravel Horizon + Redis queue
- MySQL 8
- Docker Compose
- yt-dlp + ffmpeg (baked into Docker image)

## Setup

1. Copy `.env.example` to `.env` and fill in:
   - `APP_KEY` — generate with `php artisan key:generate --show` inside the container
   - `RSYNC_HOST`, `RSYNC_USER`, `RSYNC_DEST_PATH`, `RSYNC_SSH_KEY_PATH` (for export to Unraid)

2. Build and start:
   ```bash
   docker compose up -d --build
   ```

3. Create admin user:
   ```bash
   docker compose exec app php artisan admin:create your@email.com yourpassword
   ```

4. Open http://localhost

## Running Tests

```bash
docker compose run --rm --no-deps app php artisan test
```

## Usage

1. Paste a YouTube URL → click **Preview**
2. Review title, channel, duration → click **Add to Queue**
3. Horizon processes the download in the background
4. Visit **History** to see completed downloads with file size and speed metrics
5. Click **Export** to rsync the video to your Unraid server
6. Click **Delete** to remove the local file and record
