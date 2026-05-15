<?php

namespace App\Services;

use App\Models\Download;
use App\Services\PlexNfoService;
use App\Support\PlexNaming;
use RuntimeException;

class ExportService
{
    public function export(Download $download): void
    {
        $destBase = rtrim(config('export.dest_path'), '/');
        $srcDir   = storage_path('app/private/downloads/' . $download->id);
        $videoSrc = "{$srcDir}/video.mp4";

        if (!file_exists($videoSrc)) {
            throw new RuntimeException("Video file not found: {$videoSrc}");
        }

        $channel    = PlexNaming::sanitize($download->channel);
        $season     = PlexNaming::season($download);
        $basename   = PlexNaming::basename($download);
        $episodeDir = "{$destBase}/{$channel}/Season {$season}";

        if (!is_dir($episodeDir)) {
            mkdir($episodeDir, 0755, true);
        }

        copy($videoSrc, "{$episodeDir}/{$basename}.mp4");

        if (file_exists("{$srcDir}/episode.nfo")) {
            copy("{$srcDir}/episode.nfo", "{$episodeDir}/{$basename}.nfo");
        }

        if (file_exists("{$srcDir}/thumbnail.jpg")) {
            copy("{$srcDir}/thumbnail.jpg", "{$episodeDir}/{$basename}-thumb.jpg");
        }

        $showNfoPath = "{$destBase}/{$channel}/tvshow.nfo";
        if (!file_exists($showNfoPath)) {
            file_put_contents($showNfoPath, app(PlexNfoService::class)->showNfo($download));
        }

        $download->update(['exported_at' => now()]);
    }
}
