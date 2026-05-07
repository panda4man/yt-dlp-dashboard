# Plex Metadata Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add YouTube video metadata (upload date, description, video ID) to the download pipeline, generate Plex-compatible NFO sidecar files, and restructure the export to place files in a TV-show directory hierarchy on Unraid.

**Architecture:** New `PlexNaming` helper computes Plex filenames; new `PlexNfoService` generates NFO XML. `ProcessDownload` writes `episode.nfo` alongside the video. `ExportService` uses SSH `mkdir -p` + individual named rsyncs to build `{Channel}/Season {YYYY}/{basename}.mp4|.nfo|-thumb.jpg` on the remote. Three new columns (`youtube_video_id`, `uploaded_at`, `description`) added to `downloads` table.

**Tech Stack:** PHP 8.4, Laravel 12, Pest PHP, Process facade, Carbon

**Spec:** `docs/superpowers/specs/2026-05-07-plex-metadata-design.md`

**Test command:** `docker compose run --rm --no-deps app php artisan test`

---

## File Map

| File | Change |
|---|---|
| `database/migrations/xxxx_add_plex_metadata_to_downloads.php` | New — 3 columns |
| `app/Models/Download.php` | Add fillable + `uploaded_at` date cast |
| `database/factories/DownloadFactory.php` | Add new field defaults |
| `app/Services/YtDlpService.php` | Return `id`, `uploaded_at`, `description` from `getMetadata()` |
| `app/Http/Controllers/VideoController.php` | Save new fields in `store()` |
| `app/Support/PlexNaming.php` | **New** — `sanitize()`, `basename()`, `season()`, `episode()` |
| `app/Services/PlexNfoService.php` | **New** — `episodeNfo()`, `showNfo()` |
| `app/Jobs/ProcessDownload.php` | Write `episode.nfo` after thumbnail |
| `app/Services/ExportService.php` | Structured rsync to Plex path |
| `tests/Feature/DownloadModelTest.php` | Test new fields |
| `tests/Unit/YtDlpServiceTest.php` | Test new metadata keys |
| `tests/Feature/VideoControllerTest.php` | Update mock + test new DB fields |
| `tests/Unit/PlexNamingTest.php` | **New** |
| `tests/Unit/PlexNfoServiceTest.php` | **New** |
| `tests/Feature/ProcessDownloadJobTest.php` | Test NFO file creation |
| `tests/Unit/ExportServiceTest.php` | Replace flat-rsync tests with Plex-path tests |

---

### Task 1: Migration + Download model + factory

**Files:**
- Create: `database/migrations/xxxx_add_plex_metadata_to_downloads.php`
- Modify: `app/Models/Download.php`
- Modify: `database/factories/DownloadFactory.php`
- Modify: `tests/Feature/DownloadModelTest.php`

- [ ] **Step 1: Write failing test**

Add to `tests/Feature/DownloadModelTest.php`:

```php
it('stores youtube_video_id, uploaded_at, and description', function () {
    $download = Download::factory()->create([
        'youtube_video_id' => 'dQw4w9WgXcQ',
        'uploaded_at'      => '2024-03-15',
        'description'      => 'A great video.',
    ]);

    $fresh = Download::find($download->id);

    expect($fresh->youtube_video_id)->toBe('dQw4w9WgXcQ')
        ->and($fresh->uploaded_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
        ->and($fresh->uploaded_at->format('Y-m-d'))->toBe('2024-03-15')
        ->and($fresh->description)->toBe('A great video.');
});
```

- [ ] **Step 2: Run to verify failure**

```bash
docker compose run --rm --no-deps app php artisan test tests/Feature/DownloadModelTest.php
```

Expected: FAIL — columns don't exist.

- [ ] **Step 3: Generate migration**

```bash
docker compose run --rm --no-deps app php artisan make:migration add_plex_metadata_to_downloads
```

Edit the generated file — replace `up()` body:

```php
public function up(): void
{
    Schema::table('downloads', function (Blueprint $table) {
        $table->string('youtube_video_id')->nullable()->after('thumbnail_url');
        $table->date('uploaded_at')->nullable()->after('youtube_video_id');
        $table->text('description')->nullable()->after('uploaded_at');
    });
}

public function down(): void
{
    Schema::table('downloads', function (Blueprint $table) {
        $table->dropColumn(['youtube_video_id', 'uploaded_at', 'description']);
    });
}
```

- [ ] **Step 4: Update `app/Models/Download.php`**

Add the three fields to `$fillable` (after `thumbnail_url`):

```php
protected $fillable = [
    'youtube_url',
    'title',
    'channel',
    'duration_seconds',
    'thumbnail_url',
    'youtube_video_id',
    'uploaded_at',
    'description',
    'status',
    'file_path',
    'thumbnail_path',
    'file_size_bytes',
    'download_speed_bps',
    'started_at',
    'completed_at',
    'exported_at',
    'error_message',
];

protected $casts = [
    'status'       => DownloadStatus::class,
    'uploaded_at'  => 'date',
    'started_at'   => 'datetime',
    'completed_at' => 'datetime',
    'exported_at'  => 'datetime',
];
```

- [ ] **Step 5: Update `database/factories/DownloadFactory.php`**

Add three new keys to `definition()` return array:

```php
'youtube_video_id' => 'dQw4w9WgXcQ',
'uploaded_at'      => '2024-01-15',
'description'      => 'A test video description.',
```

- [ ] **Step 6: Run tests**

```bash
docker compose run --rm --no-deps app php artisan test tests/Feature/DownloadModelTest.php
```

Expected: PASS

- [ ] **Step 7: Run full suite**

```bash
docker compose run --rm --no-deps app php artisan test
```

Expected: All tests pass.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: add youtube_video_id, uploaded_at, description to downloads"
```

---

### Task 2: YtDlpService — return new metadata keys

**Files:**
- Modify: `app/Services/YtDlpService.php`
- Modify: `tests/Unit/YtDlpServiceTest.php`

- [ ] **Step 1: Write failing test**

Add to `tests/Unit/YtDlpServiceTest.php`:

```php
it('returns id, uploaded_at, and description from metadata', function () {
    Process::fake([
        '*yt-dlp*' => Process::result(
            output: json_encode([
                'title'       => 'Test Video',
                'uploader'    => 'Test Channel',
                'duration'    => 245,
                'thumbnail'   => 'https://i.ytimg.com/vi/abc/maxresdefault.jpg',
                'id'          => 'abc123',
                'upload_date' => '20240315',
                'description' => 'A test description.',
            ]),
            exitCode: 0
        ),
    ]);

    $metadata = (new YtDlpService())->getMetadata('https://youtube.com/watch?v=abc123');

    expect($metadata['id'])->toBe('abc123')
        ->and($metadata['uploaded_at'])->toBe('2024-03-15')
        ->and($metadata['description'])->toBe('A test description.');
});

it('returns null uploaded_at when upload_date missing', function () {
    Process::fake([
        '*yt-dlp*' => Process::result(
            output: json_encode(['title' => 'Test', 'uploader' => 'Chan', 'duration' => 10, 'thumbnail' => '']),
            exitCode: 0
        ),
    ]);

    $metadata = (new YtDlpService())->getMetadata('https://youtube.com/watch?v=abc123');

    expect($metadata['uploaded_at'])->toBeNull()
        ->and($metadata['id'])->toBe('')
        ->and($metadata['description'])->toBeNull();
});
```

- [ ] **Step 2: Run to verify failure**

```bash
docker compose run --rm --no-deps app php artisan test tests/Unit/YtDlpServiceTest.php
```

Expected: 2 new tests FAIL — keys not returned.

- [ ] **Step 3: Update `app/Services/YtDlpService.php` — `getMetadata()` return value**

Replace the return statement in `getMetadata()`:

```php
return [
    'title'       => $data['title'] ?? 'Unknown',
    'channel'     => $data['uploader'] ?? $data['channel'] ?? 'Unknown',
    'duration'    => (int) ($data['duration'] ?? 0),
    'thumbnail'   => $data['thumbnail'] ?? '',
    'id'          => $data['id'] ?? '',
    'uploaded_at' => isset($data['upload_date'])
        ? \Carbon\Carbon::createFromFormat('Ymd', $data['upload_date'])->toDateString()
        : null,
    'description' => $data['description'] ?? null,
];
```

- [ ] **Step 4: Run tests**

```bash
docker compose run --rm --no-deps app php artisan test tests/Unit/YtDlpServiceTest.php
```

Expected: All YtDlpService tests PASS.

- [ ] **Step 5: Run full suite**

```bash
docker compose run --rm --no-deps app php artisan test
```

Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Services/YtDlpService.php tests/Unit/YtDlpServiceTest.php
git commit -m "feat: return video id, upload date, description from YtDlpService"
```

---

### Task 3: VideoController — save new metadata fields

**Files:**
- Modify: `app/Http/Controllers/VideoController.php`
- Modify: `tests/Feature/VideoControllerTest.php`

- [ ] **Step 1: Write failing test**

Add to `tests/Feature/VideoControllerTest.php`:

```php
it('saves youtube_video_id, uploaded_at, and description on queue submit', function () {
    Queue::fake();

    $mock = Mockery::mock(YtDlpService::class);
    $mock->shouldReceive('getMetadata')->andReturn([
        'title'       => 'My Video',
        'channel'     => 'My Channel',
        'duration'    => 300,
        'thumbnail'   => 'https://i.ytimg.com/vi/abc/default.jpg',
        'id'          => 'abc123',
        'uploaded_at' => '2024-03-15',
        'description' => 'A great video.',
    ]);
    app()->instance(YtDlpService::class, $mock);

    $this->post('/videos', ['url' => 'https://youtube.com/watch?v=abc123']);

    $this->assertDatabaseHas('downloads', [
        'youtube_url'      => 'https://youtube.com/watch?v=abc123',
        'youtube_video_id' => 'abc123',
        'uploaded_at'      => '2024-03-15',
    ]);
});
```

- [ ] **Step 2: Run to verify failure**

```bash
docker compose run --rm --no-deps app php artisan test tests/Feature/VideoControllerTest.php
```

Expected: new test FAIL — fields not saved.

- [ ] **Step 3: Update `app/Http/Controllers/VideoController.php` — `store()` method**

In `store()`, replace the `Download::create([...])` call with:

```php
$download = Download::create([
    'youtube_url'      => $url,
    'title'            => $metadata['title'],
    'channel'          => $metadata['channel'],
    'duration_seconds' => $metadata['duration'],
    'thumbnail_url'    => $metadata['thumbnail'],
    'youtube_video_id' => $metadata['id'] ?? null,
    'uploaded_at'      => $metadata['uploaded_at'] ?? null,
    'description'      => $metadata['description'] ?? null,
    'status'           => DownloadStatus::Pending,
]);
```

- [ ] **Step 4: Run tests**

```bash
docker compose run --rm --no-deps app php artisan test tests/Feature/VideoControllerTest.php
```

Expected: All VideoController tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/VideoController.php tests/Feature/VideoControllerTest.php
git commit -m "feat: save video id, upload date, description on queue submit"
```

---

### Task 4: PlexNaming helper

**Files:**
- Create: `app/Support/PlexNaming.php`
- Create: `tests/Unit/PlexNamingTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/PlexNamingTest.php`:

```php
<?php

use App\Enums\DownloadStatus;
use App\Models\Download;
use App\Support\PlexNaming;

it('strips illegal filesystem characters', function () {
    expect(PlexNaming::sanitize('Channel: "Best/Videos*Now"'))
        ->toBe('Channel BestVideosNow');
});

it('collapses multiple spaces after stripping', function () {
    expect(PlexNaming::sanitize('A  : B'))->toBe('A  B');
});

it('trims leading and trailing whitespace', function () {
    expect(PlexNaming::sanitize('  My Channel  '))->toBe('My Channel');
});

it('returns upload year as season', function () {
    $download = Download::factory()->create(['uploaded_at' => '2024-03-15']);
    expect(PlexNaming::season($download))->toBe(2024);
});

it('falls back to created_at year when uploaded_at is null', function () {
    $download = Download::factory()->create(['uploaded_at' => null]);
    expect(PlexNaming::season($download))->toBe((int) $download->created_at->format('Y'));
});

it('returns MMDD as episode string', function () {
    $download = Download::factory()->completed()->create([
        'channel'    => 'Solo Channel',
        'uploaded_at' => '2024-03-15',
    ]);
    expect(PlexNaming::episode($download))->toBe('0315');
});

it('appends b suffix on same-channel same-day collision', function () {
    Download::factory()->completed()->create([
        'channel'    => 'My Channel',
        'uploaded_at' => '2024-03-15',
    ]);
    $second = Download::factory()->completed()->create([
        'channel'    => 'My Channel',
        'uploaded_at' => '2024-03-15',
    ]);
    expect(PlexNaming::episode($second))->toBe('0315b');
});

it('does not count failed or pending downloads as collisions', function () {
    Download::factory()->create([
        'channel'    => 'My Channel',
        'uploaded_at' => '2024-03-15',
        'status'      => DownloadStatus::Failed,
    ]);
    $download = Download::factory()->completed()->create([
        'channel'    => 'My Channel',
        'uploaded_at' => '2024-03-15',
    ]);
    expect(PlexNaming::episode($download))->toBe('0315');
});

it('generates basename from download', function () {
    $download = Download::factory()->completed()->create([
        'title'      => 'My Video',
        'channel'    => 'My Channel',
        'uploaded_at' => '2024-03-15',
    ]);
    expect(PlexNaming::basename($download))->toBe('My Channel - S2024E0315 - My Video');
});

it('returns fallback episode 0101 when uploaded_at is null', function () {
    $download = Download::factory()->completed()->create(['uploaded_at' => null]);
    expect(PlexNaming::episode($download))->toBe('0101');
});
```

- [ ] **Step 2: Run to verify failure**

```bash
docker compose run --rm --no-deps app php artisan test tests/Unit/PlexNamingTest.php
```

Expected: FAIL — `PlexNaming` not found.

- [ ] **Step 3: Create `app/Support/PlexNaming.php`**

First create directory:
```bash
mkdir -p /home/aclinton/Dev/yt-dlp-dashboad/app/Support
```

```php
<?php

namespace App\Support;

use App\Enums\DownloadStatus;
use App\Models\Download;

class PlexNaming
{
    public static function sanitize(string $str): string
    {
        $str = preg_replace('/[\/\\\\:*?"<>|]/', '', $str);
        $str = preg_replace('/\s+/', ' ', $str);
        return trim($str);
    }

    public static function season(Download $download): int
    {
        return $download->uploaded_at
            ? (int) $download->uploaded_at->format('Y')
            : (int) $download->created_at->format('Y');
    }

    public static function episode(Download $download): string
    {
        if (!$download->uploaded_at) {
            return '0101';
        }

        $mmdd = $download->uploaded_at->format('md');

        $collisions = Download::where('channel', $download->channel)
            ->where('uploaded_at', $download->uploaded_at->toDateString())
            ->where('status', DownloadStatus::Completed)
            ->where('id', '<', $download->id)
            ->count();

        if ($collisions === 0) {
            return $mmdd;
        }

        $suffixes = array_merge(range('b', 'z'), range('aa', 'az'));
        return $mmdd . ($suffixes[$collisions - 1] ?? (string) $collisions);
    }

    public static function basename(Download $download): string
    {
        $channel = self::sanitize($download->channel);
        $season  = self::season($download);
        $episode = self::episode($download);
        $title   = self::sanitize($download->title);

        return "{$channel} - S{$season}E{$episode} - {$title}";
    }
}
```

- [ ] **Step 4: Run tests**

```bash
docker compose run --rm --no-deps app php artisan test tests/Unit/PlexNamingTest.php
```

Expected: All PASS.

- [ ] **Step 5: Run full suite**

```bash
docker compose run --rm --no-deps app php artisan test
```

Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Support/PlexNaming.php tests/Unit/PlexNamingTest.php
git commit -m "feat: add PlexNaming helper"
```

---

### Task 5: PlexNfoService

**Files:**
- Create: `app/Services/PlexNfoService.php`
- Create: `tests/Unit/PlexNfoServiceTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/PlexNfoServiceTest.php`:

```php
<?php

use App\Enums\DownloadStatus;
use App\Models\Download;
use App\Services\PlexNfoService;

it('generates valid episode NFO XML', function () {
    $download = Download::factory()->completed()->create([
        'title'            => 'My Video',
        'channel'          => 'My Channel',
        'uploaded_at'      => '2024-03-15',
        'description'      => 'A great video.',
        'youtube_video_id' => 'abc123',
    ]);

    $xml = (new PlexNfoService())->episodeNfo($download);

    expect($xml)->toContain('<title>My Video</title>')
        ->toContain('<showtitle>My Channel</showtitle>')
        ->toContain('<season>2024</season>')
        ->toContain('<episode>315</episode>')
        ->toContain('<plot>A great video.</plot>')
        ->toContain('<premiered>2024-03-15</premiered>')
        ->toContain('<studio>My Channel</studio>')
        ->toContain('<thumb>thumbnail.jpg</thumb>')
        ->toContain('<uniqueid type="youtube">abc123</uniqueid>');
});

it('omits premiered tag when uploaded_at is null', function () {
    $download = Download::factory()->completed()->create([
        'uploaded_at' => null,
    ]);

    $xml = (new PlexNfoService())->episodeNfo($download);

    expect($xml)->not->toContain('<premiered>');
});

it('generates valid show NFO XML', function () {
    $download = Download::factory()->create(['channel' => 'My Channel']);

    $xml = (new PlexNfoService())->showNfo($download);

    expect($xml)->toContain('<title>My Channel</title>');
    expect($xml)->toStartWith('<?xml');
});
```

- [ ] **Step 2: Run to verify failure**

```bash
docker compose run --rm --no-deps app php artisan test tests/Unit/PlexNfoServiceTest.php
```

Expected: FAIL — `PlexNfoService` not found.

- [ ] **Step 3: Create `app/Services/PlexNfoService.php`**

```php
<?php

namespace App\Services;

use App\Models\Download;
use App\Support\PlexNaming;

class PlexNfoService
{
    public function episodeNfo(Download $download): string
    {
        $season  = PlexNaming::season($download);
        $episode = (int) PlexNaming::episode($download); // (int) strips any b/c suffix
        $title   = htmlspecialchars($download->title, ENT_XML1);
        $channel = htmlspecialchars($download->channel, ENT_XML1);
        $plot    = htmlspecialchars($download->description ?? '', ENT_XML1);
        $videoId = htmlspecialchars($download->youtube_video_id ?? '', ENT_XML1);

        $lines = [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<episodedetails>',
            "  <title>{$title}</title>",
            "  <showtitle>{$channel}</showtitle>",
            "  <season>{$season}</season>",
            "  <episode>{$episode}</episode>",
            "  <plot>{$plot}</plot>",
        ];

        if ($download->uploaded_at) {
            $lines[] = "  <premiered>{$download->uploaded_at->format('Y-m-d')}</premiered>";
        }

        array_push($lines,
            "  <studio>{$channel}</studio>",
            '  <thumb>thumbnail.jpg</thumb>',
            "  <uniqueid type=\"youtube\">{$videoId}</uniqueid>",
            '</episodedetails>',
        );

        return implode("\n", $lines) . "\n";
    }

    public function showNfo(Download $download): string
    {
        $channel = htmlspecialchars($download->channel, ENT_XML1);

        return implode("\n", [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<tvshow>',
            "  <title>{$channel}</title>",
            '</tvshow>',
        ]) . "\n";
    }
}
```

- [ ] **Step 4: Run tests**

```bash
docker compose run --rm --no-deps app php artisan test tests/Unit/PlexNfoServiceTest.php
```

Expected: All PASS.

- [ ] **Step 5: Run full suite**

```bash
docker compose run --rm --no-deps app php artisan test
```

Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Services/PlexNfoService.php tests/Unit/PlexNfoServiceTest.php
git commit -m "feat: add PlexNfoService"
```

---

### Task 6: ProcessDownload — write episode.nfo

**Files:**
- Modify: `app/Jobs/ProcessDownload.php`
- Modify: `tests/Feature/ProcessDownloadJobTest.php`

- [ ] **Step 1: Write failing test**

Add to `tests/Feature/ProcessDownloadJobTest.php`:

```php
it('writes episode.nfo to download directory', function () {
    $download = Download::factory()->create([
        'youtube_url'   => 'https://youtube.com/watch?v=abc123',
        'thumbnail_url' => 'https://i.ytimg.com/vi/abc/default.jpg',
        'status'        => DownloadStatus::Pending,
        'uploaded_at'   => '2024-03-15',
        'channel'       => 'My Channel',
        'title'         => 'My Video',
    ]);

    $ytDlp = Mockery::mock(YtDlpService::class);
    $ytDlp->shouldReceive('download')->once()->andReturnUsing(function ($url, $dir) {
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $path = $dir . '/video.mp4';
        file_put_contents($path, str_repeat('x', 1024));
        return $path;
    });

    $thumbnail = Mockery::mock(ThumbnailService::class);
    $thumbnail->shouldReceive('generate')->once()->andReturnUsing(function ($thumbnailUrl, $dir) {
        $path = $dir . '/thumbnail.jpg';
        file_put_contents($path, 'img');
        return $path;
    });

    app()->instance(YtDlpService::class, $ytDlp);
    app()->instance(ThumbnailService::class, $thumbnail);

    (new ProcessDownload($download))->handle($ytDlp, $thumbnail);

    $nfoPath = storage_path('app/private/downloads/' . $download->id . '/episode.nfo');
    expect(file_exists($nfoPath))->toBeTrue();

    $xml = file_get_contents($nfoPath);
    expect($xml)->toContain('<title>My Video</title>')
        ->toContain('<showtitle>My Channel</showtitle>');
});
```

- [ ] **Step 2: Run to verify failure**

```bash
docker compose run --rm --no-deps app php artisan test tests/Feature/ProcessDownloadJobTest.php
```

Expected: new test FAIL — `episode.nfo` not written.

- [ ] **Step 3: Update `app/Jobs/ProcessDownload.php` — add NFO write after thumbnail**

In `handle()`, after `$thumbnailPath = $thumbnail->generate(...)`, add:

```php
$nfoContent = app(\App\Services\PlexNfoService::class)->episodeNfo($this->download);
file_put_contents($outputDir . '/episode.nfo', $nfoContent);
```

Full updated `handle()` method:

```php
public function handle(YtDlpService $ytDlp, ThumbnailService $thumbnail): void
{
    $this->download->update([
        'status'     => DownloadStatus::Processing,
        'started_at' => now(),
    ]);

    try {
        $outputDir = storage_path('app/private/downloads/' . $this->download->id);

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $filePath      = $ytDlp->download($this->download->youtube_url, $outputDir);
        $thumbnailPath = $thumbnail->generate($this->download->thumbnail_url, $outputDir);

        $nfoContent = app(\App\Services\PlexNfoService::class)->episodeNfo($this->download);
        file_put_contents($outputDir . '/episode.nfo', $nfoContent);

        $fileSize    = filesize($filePath);
        $completedAt = now();
        $elapsed     = max(1, $completedAt->diffInSeconds($this->download->started_at));

        $this->download->update([
            'status'             => DownloadStatus::Completed,
            'file_path'          => 'downloads/' . $this->download->id . '/video.mp4',
            'thumbnail_path'     => 'downloads/' . $this->download->id . '/thumbnail.jpg',
            'file_size_bytes'    => $fileSize,
            'download_speed_bps' => (int) ($fileSize / $elapsed),
            'completed_at'       => $completedAt,
        ]);
    } catch (Throwable $e) {
        $this->download->update([
            'status'        => DownloadStatus::Failed,
            'error_message' => $e->getMessage(),
        ]);
        throw $e;
    }
}
```

- [ ] **Step 4: Run tests**

```bash
docker compose run --rm --no-deps app php artisan test tests/Feature/ProcessDownloadJobTest.php
```

Expected: All PASS.

- [ ] **Step 5: Run full suite**

```bash
docker compose run --rm --no-deps app php artisan test
```

Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/ProcessDownload.php tests/Feature/ProcessDownloadJobTest.php
git commit -m "feat: write episode.nfo in ProcessDownload job"
```

---

### Task 7: ExportService — structured Plex rsync

**Files:**
- Modify: `app/Services/ExportService.php`
- Modify: `tests/Unit/ExportServiceTest.php`

- [ ] **Step 1: Replace `tests/Unit/ExportServiceTest.php`**

The existing flat-rsync tests are superseded. Replace the entire file:

```php
<?php

use App\Models\Download;
use App\Services\ExportService;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config([
        'export.rsync_host'     => 'unraid.local',
        'export.rsync_user'     => 'root',
        'export.rsync_dest'     => '/mnt/media/ytdlp',
        'export.rsync_key_path' => '/run/secrets/id_rsa',
    ]);
});

it('creates remote season directory via ssh', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

    $download = Download::factory()->completed()->create([
        'channel'    => 'My Channel',
        'uploaded_at' => '2024-03-15',
    ]);

    (new ExportService())->export($download);

    Process::assertRan(fn ($p) =>
        str_contains($p->command(), 'ssh') &&
        str_contains($p->command(), 'mkdir')
    );
});

it('rsyncs video, nfo, and thumbnail with Plex names', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

    $download = Download::factory()->completed()->create([
        'channel'    => 'My Channel',
        'title'      => 'My Video',
        'uploaded_at' => '2024-03-15',
    ]);

    (new ExportService())->export($download);

    Process::assertRan(fn ($p) =>
        str_contains($p->command(), 'rsync') &&
        str_contains($p->command(), 'video.mp4') &&
        str_contains($p->command(), 'My Channel - S2024E0315 - My Video.mp4')
    );

    Process::assertRan(fn ($p) =>
        str_contains($p->command(), 'rsync') &&
        str_contains($p->command(), 'episode.nfo') &&
        str_contains($p->command(), 'My Channel - S2024E0315 - My Video.nfo')
    );

    Process::assertRan(fn ($p) =>
        str_contains($p->command(), 'rsync') &&
        str_contains($p->command(), 'thumbnail.jpg') &&
        str_contains($p->command(), 'My Channel - S2024E0315 - My Video-thumb.jpg')
    );
});

it('rsyncs tvshow.nfo to show root', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

    $download = Download::factory()->completed()->create([
        'channel' => 'My Channel',
    ]);

    (new ExportService())->export($download);

    Process::assertRan(fn ($p) =>
        str_contains($p->command(), 'rsync') &&
        str_contains($p->command(), 'tvshow.nfo')
    );
});

it('sets exported_at on success', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

    $download = Download::factory()->completed()->create();

    (new ExportService())->export($download);

    expect($download->fresh()->exported_at)->not->toBeNull();
});

it('throws RuntimeException when ssh mkdir fails', function () {
    Process::fake(['*ssh*' => Process::result(output: '', errorOutput: 'Connection refused', exitCode: 1)]);

    $download = Download::factory()->completed()->create();

    expect(fn () => (new ExportService())->export($download))
        ->toThrow(RuntimeException::class, 'Connection refused');
});

it('throws RuntimeException when rsync fails', function () {
    Process::fake([
        '*ssh*'   => Process::result(output: '', exitCode: 0),
        '*rsync*' => Process::result(output: '', errorOutput: 'rsync error', exitCode: 11),
    ]);

    $download = Download::factory()->completed()->create();

    expect(fn () => (new ExportService())->export($download))
        ->toThrow(RuntimeException::class, 'rsync error');
});
```

- [ ] **Step 2: Run to verify failure**

```bash
docker compose run --rm --no-deps app php artisan test tests/Unit/ExportServiceTest.php
```

Expected: Multiple FAIL — ExportService doesn't yet do structured export.

- [ ] **Step 3: Replace `app/Services/ExportService.php`**

```php
<?php

namespace App\Services;

use App\Models\Download;
use App\Support\PlexNaming;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class ExportService
{
    public function export(Download $download): void
    {
        $host    = config('export.rsync_host');
        $user    = config('export.rsync_user');
        $dest    = config('export.rsync_dest');
        $keyPath = config('export.rsync_key_path');

        $sshBase    = ['ssh', '-i', $keyPath, '-o', 'StrictHostKeyChecking=no'];
        $rsyncSsh   = "ssh -i {$keyPath} -o StrictHostKeyChecking=no";

        $channel   = PlexNaming::sanitize($download->channel);
        $basename  = PlexNaming::basename($download);
        $season    = PlexNaming::season($download);
        $showDir   = "{$dest}/{$channel}";
        $seasonDir = "{$showDir}/Season {$season}";
        $localDir  = storage_path('app/private/downloads/' . $download->id);

        // Create remote directory structure
        $mkdir = Process::run(array_merge($sshBase, [
            "{$user}@{$host}",
            "mkdir -p \"{$seasonDir}\"",
        ]));

        if (!$mkdir->successful()) {
            throw new RuntimeException('Failed to create remote directory: ' . $mkdir->errorOutput());
        }

        // Rsync episode files with Plex names
        $files = [
            "{$localDir}/video.mp4"    => "{$user}@{$host}:{$seasonDir}/{$basename}.mp4",
            "{$localDir}/episode.nfo"  => "{$user}@{$host}:{$seasonDir}/{$basename}.nfo",
            "{$localDir}/thumbnail.jpg" => "{$user}@{$host}:{$seasonDir}/{$basename}-thumb.jpg",
        ];

        foreach ($files as $local => $remote) {
            $result = Process::run([
                'rsync', '-avz', '-e', $rsyncSsh, $local, $remote,
            ]);

            if (!$result->successful()) {
                throw new RuntimeException("rsync failed for {$local}: " . $result->errorOutput());
            }
        }

        // Write and rsync tvshow.nfo to show root (idempotent)
        $showNfoContent = app(PlexNfoService::class)->showNfo($download);
        $tmpNfo = sys_get_temp_dir() . '/tvshow_' . $download->id . '.nfo';
        file_put_contents($tmpNfo, $showNfoContent);

        $result = Process::run([
            'rsync', '-avz', '-e', $rsyncSsh,
            $tmpNfo,
            "{$user}@{$host}:{$showDir}/tvshow.nfo",
        ]);

        if (file_exists($tmpNfo)) {
            unlink($tmpNfo);
        }

        if (!$result->successful()) {
            throw new RuntimeException('rsync tvshow.nfo failed: ' . $result->errorOutput());
        }

        $download->update(['exported_at' => now()]);
    }
}
```

- [ ] **Step 4: Run tests**

```bash
docker compose run --rm --no-deps app php artisan test tests/Unit/ExportServiceTest.php
```

Expected: All PASS.

- [ ] **Step 5: Run full suite**

```bash
docker compose run --rm --no-deps app php artisan test
```

Expected: All tests pass.

- [ ] **Step 6: Rebuild Docker and deploy**

```bash
docker compose build && docker compose up -d
```

- [ ] **Step 7: Commit**

```bash
git add app/Services/ExportService.php tests/Unit/ExportServiceTest.php
git commit -m "feat: structured Plex rsync export with NFO sidecars"
```
