<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class ThumbnailService
{
    public function generate(string $thumbnailUrl, string $outputDir): string
    {
        $rawPath    = $outputDir . '/thumbnail_raw.jpg';
        $outputPath = $outputDir . '/thumbnail.jpg';

        $response = Http::get($thumbnailUrl);
        if (!$response->successful()) {
            throw new RuntimeException('Failed to download thumbnail');
        }
        file_put_contents($rawPath, $response->body());

        // Center-crop to square: take min(width, height) on each axis
        $result = Process::run([
            'ffmpeg', '-i', $rawPath,
            '-vf', "crop='min(iw,ih)':'min(iw,ih)'",
            '-y', $outputPath,
        ]);

        if (file_exists($rawPath)) {
            unlink($rawPath);
        }

        if (!$result->successful()) {
            throw new RuntimeException('ffmpeg crop failed: ' . $result->errorOutput());
        }

        return $outputPath;
    }
}
