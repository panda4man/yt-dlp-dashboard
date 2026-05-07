<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class YtDlpService
{
    public function getMetadata(string $url): array
    {
        $result = Process::run([
            'yt-dlp', '--dump-json', '--no-playlist', $url,
        ]);

        if (!$result->successful()) {
            throw new RuntimeException($result->errorOutput() ?: 'yt-dlp failed');
        }

        $data = json_decode($result->output(), true);

        return [
            'title'     => $data['title'] ?? 'Unknown',
            'channel'   => $data['uploader'] ?? $data['channel'] ?? 'Unknown',
            'duration'  => (int) ($data['duration'] ?? 0),
            'thumbnail' => $data['thumbnail'] ?? '',
        ];
    }

    public function download(string $url, string $outputDir): string
    {
        $result = Process::run([
            'yt-dlp',
            '-f', 'bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4]/best',
            '--merge-output-format', 'mp4',
            '-o', $outputDir . '/video.%(ext)s',
            '--no-playlist',
            $url,
        ]);

        if (!$result->successful()) {
            throw new RuntimeException($result->errorOutput() ?: 'yt-dlp download failed');
        }

        return $outputDir . '/video.mp4';
    }
}
