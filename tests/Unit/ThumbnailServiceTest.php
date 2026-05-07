<?php

use App\Services\ThumbnailService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

it('downloads thumbnail and crops to square', function () {
    Http::fake([
        'https://i.ytimg.com/*' => Http::response(str_repeat('x', 512), 200),
    ]);
    Process::fake([
        '*ffmpeg*' => Process::result(output: '', exitCode: 0),
    ]);

    $outputDir = sys_get_temp_dir() . '/thumb-test-' . uniqid();
    mkdir($outputDir, 0755, true);

    $path = (new ThumbnailService())->generate(
        'https://i.ytimg.com/vi/abc/maxresdefault.jpg',
        $outputDir
    );

    expect($path)->toBe($outputDir . '/thumbnail.jpg');

    if (file_exists($path)) unlink($path);
    rmdir($outputDir);
});

it('throws RuntimeException when ffmpeg fails', function () {
    Http::fake([
        'https://i.ytimg.com/*' => Http::response(str_repeat('x', 512), 200),
    ]);
    Process::fake([
        '*ffmpeg*' => Process::result(output: '', errorOutput: 'Invalid data', exitCode: 1),
    ]);

    $outputDir = sys_get_temp_dir() . '/thumb-test-' . uniqid();
    mkdir($outputDir, 0755, true);

    expect(fn () => (new ThumbnailService())->generate(
        'https://i.ytimg.com/vi/abc/maxresdefault.jpg',
        $outputDir
    ))->toThrow(RuntimeException::class);

    if (is_dir($outputDir)) rmdir($outputDir);
});
