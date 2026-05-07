<?php

use App\Services\YtDlpService;
use Illuminate\Support\Facades\Process;

it('returns structured metadata from yt-dlp', function () {
    Process::fake([
        '*yt-dlp*' => Process::result(
            output: json_encode([
                'title'     => 'Test Video',
                'uploader'  => 'Test Channel',
                'duration'  => 245,
                'thumbnail' => 'https://i.ytimg.com/vi/abc/maxresdefault.jpg',
            ]),
            exitCode: 0
        ),
    ]);

    $metadata = (new YtDlpService())->getMetadata('https://youtube.com/watch?v=abc123');

    expect($metadata['title'])->toBe('Test Video')
        ->and($metadata['channel'])->toBe('Test Channel')
        ->and($metadata['duration'])->toBe(245)
        ->and($metadata['thumbnail'])->toBe('https://i.ytimg.com/vi/abc/maxresdefault.jpg');
});

it('throws RuntimeException when yt-dlp fails', function () {
    Process::fake([
        '*yt-dlp*' => Process::result(
            output: '',
            errorOutput: 'ERROR: Video unavailable',
            exitCode: 1
        ),
    ]);

    expect(fn () => (new YtDlpService())->getMetadata('https://youtube.com/watch?v=bad'))
        ->toThrow(RuntimeException::class, 'ERROR: Video unavailable');
});

it('downloads video and returns file path', function () {
    Process::fake([
        '*yt-dlp*' => Process::result(output: '', exitCode: 0),
    ]);

    $outputDir = sys_get_temp_dir() . '/ytdlp-test-' . uniqid();
    mkdir($outputDir, 0755, true);
    file_put_contents($outputDir . '/video.mp4', 'fake');

    $path = (new YtDlpService())->download('https://youtube.com/watch?v=abc123', $outputDir);

    expect($path)->toBe($outputDir . '/video.mp4');

    unlink($outputDir . '/video.mp4');
    rmdir($outputDir);
});

it('throws RuntimeException when download fails', function () {
    Process::fake([
        '*yt-dlp*' => Process::result(output: '', errorOutput: 'Download failed', exitCode: 1),
    ]);

    $outputDir = sys_get_temp_dir() . '/ytdlp-test-' . uniqid();
    mkdir($outputDir, 0755, true);

    expect(fn () => (new YtDlpService())->download('https://youtube.com/watch?v=bad', $outputDir))
        ->toThrow(RuntimeException::class, 'Download failed');

    rmdir($outputDir);
});

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
