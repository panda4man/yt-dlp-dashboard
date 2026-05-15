<?php

namespace App\Jobs;

use App\Enums\DownloadStatus;
use App\Models\Download;
use App\Services\ExportService;
use App\Services\PlexService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExportDownload implements ShouldQueue
{
    use Queueable;

    public function __construct(public Download $download) {}

    public function handle(ExportService $exporter, PlexService $plex): void
    {
        $this->download->update(['status' => DownloadStatus::Exporting, 'export_error' => null]);

        try {
            $exporter->export($this->download);
        } catch (Throwable $e) {
            $this->download->update([
                'status'       => DownloadStatus::ExportFailed,
                'export_error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->download->update(['status' => DownloadStatus::Completed]);

        try {
            $plex->refreshLibrary();
            $this->download->update(['plex_refreshed_at' => now()]);
        } catch (Throwable $e) {
            $this->download->update(['plex_error' => $e->getMessage()]);
        }
    }

    public function failed(Throwable $e): void
    {
        if ($this->download->status !== DownloadStatus::ExportFailed) {
            $this->download->update([
                'status'       => DownloadStatus::ExportFailed,
                'export_error' => $e->getMessage(),
            ]);
        }
    }
}
