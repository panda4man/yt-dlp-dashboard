<?php

namespace App\Jobs;

use App\Enums\DownloadStatus;
use App\Models\Download;
use App\Services\ThumbnailService;
use App\Services\YtDlpService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessDownload implements ShouldQueue
{
    use Queueable;

    public function __construct(public Download $download) {}

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

    public function failed(Throwable $exception): void
    {
        $this->download->update([
            'status'        => DownloadStatus::Failed,
            'error_message' => $exception->getMessage(),
        ]);
    }
}
