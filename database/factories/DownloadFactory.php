<?php

namespace Database\Factories;

use App\Enums\DownloadStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class DownloadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'youtube_url'        => 'https://youtube.com/watch?v=' . $this->faker->regexify('[A-Za-z0-9_-]{11}'),
            'title'              => $this->faker->sentence(4),
            'channel'            => $this->faker->company(),
            'duration_seconds'   => $this->faker->numberBetween(60, 3600),
            'thumbnail_url'      => 'https://i.ytimg.com/vi/abc/maxresdefault.jpg',
            'status'             => DownloadStatus::Pending,
            'file_path'          => null,
            'thumbnail_path'     => null,
            'file_size_bytes'    => null,
            'download_speed_bps' => null,
            'started_at'         => null,
            'completed_at'       => null,
            'exported_at'        => null,
            'error_message'      => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status'             => DownloadStatus::Completed,
            'file_path'          => 'downloads/1/video.mp4',
            'thumbnail_path'     => 'downloads/1/thumbnail.jpg',
            'file_size_bytes'    => 104857600,
            'download_speed_bps' => 1048576,
            'started_at'         => now()->subMinutes(2),
            'completed_at'       => now(),
        ]);
    }
}
