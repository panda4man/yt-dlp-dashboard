<?php

use App\Enums\DownloadStatus;
use App\Jobs\ProcessDownload;
use App\Models\Download;
use App\Services\ThumbnailService;
use App\Services\YtDlpService;

it('processes download successfully and updates model', function () {
    $download = Download::factory()->create([
        'youtube_url'   => 'https://youtube.com/watch?v=abc123',
        'thumbnail_url' => 'https://i.ytimg.com/vi/abc/default.jpg',
        'status'        => DownloadStatus::Pending,
    ]);

    $ytDlp = Mockery::mock(YtDlpService::class);
    $ytDlp->shouldReceive('download')->once()->andReturnUsing(function ($url, $dir) {
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $path = $dir . '/video.mp4';
        file_put_contents($path, str_repeat('x', 1024 * 1024));
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

    $download->refresh();

    expect($download->status)->toBe(DownloadStatus::Completed)
        ->and($download->file_path)->not->toBeNull()
        ->and($download->thumbnail_path)->not->toBeNull()
        ->and($download->file_size_bytes)->toBe(1024 * 1024)
        ->and($download->download_speed_bps)->toBeGreaterThan(0)
        ->and($download->started_at)->not->toBeNull()
        ->and($download->completed_at)->not->toBeNull();
});

it('marks download as failed via the failed() queue callback', function () {
    $download = Download::factory()->create(['status' => DownloadStatus::Pending]);

    $job = new ProcessDownload($download);
    $job->failed(new RuntimeException('Queue-level failure'));

    $download->refresh();

    expect($download->status)->toBe(DownloadStatus::Failed)
        ->and($download->error_message)->toBe('Queue-level failure');
});

it('marks download as failed when yt-dlp throws', function () {
    $download = Download::factory()->create(['status' => DownloadStatus::Pending]);

    $ytDlp = Mockery::mock(YtDlpService::class);
    $ytDlp->shouldReceive('download')
        ->andThrow(new RuntimeException('Video unavailable'));

    $thumbnail = Mockery::mock(ThumbnailService::class);

    app()->instance(YtDlpService::class, $ytDlp);
    app()->instance(ThumbnailService::class, $thumbnail);

    expect(fn () => (new ProcessDownload($download))->handle($ytDlp, $thumbnail))
        ->toThrow(RuntimeException::class);

    $download->refresh();

    expect($download->status)->toBe(DownloadStatus::Failed)
        ->and($download->error_message)->toBe('Video unavailable');
});
