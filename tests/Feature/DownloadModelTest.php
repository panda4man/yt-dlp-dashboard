<?php

namespace Tests\Feature;

use App\Enums\DownloadStatus;
use App\Models\Download;
use Tests\TestCase;

class DownloadModelTest extends TestCase
{
    public function test_creates_a_download_with_pending_status(): void
    {
        $download = Download::factory()->create([
            'youtube_url' => 'https://youtube.com/watch?v=abc123',
            'title' => 'Test Video',
            'channel' => 'Test Channel',
            'duration_seconds' => 120,
            'status' => DownloadStatus::Pending,
        ]);

        $this->assertSame(DownloadStatus::Pending, $download->status);
        $this->assertNull($download->file_path);
        $this->assertNull($download->exported_at);
    }

    public function test_casts_status_as_download_status_enum(): void
    {
        $download = Download::factory()->create(['status' => DownloadStatus::Completed]);

        $this->assertSame(DownloadStatus::Completed, Download::find($download->id)->status);
    }
}
