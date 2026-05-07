<?php

namespace App\Support;

use App\Enums\DownloadStatus;
use App\Models\Download;

class PlexNaming
{
    public static function sanitize(string $str): string
    {
        $str = preg_replace('/[\/\\\\:*?"<>|]/', '', $str);
        $str = preg_replace('/\s+/', ' ', $str);
        return trim($str);
    }

    public static function season(Download $download): int
    {
        return $download->uploaded_at
            ? (int) $download->uploaded_at->format('Y')
            : (int) $download->created_at->format('Y');
    }

    public static function episode(Download $download): string
    {
        if (!$download->uploaded_at) {
            return '0101';
        }

        $mmdd = $download->uploaded_at->format('md');

        $collisions = Download::where('channel', $download->channel)
            ->where('uploaded_at', $download->uploaded_at->toDateString())
            ->where('status', DownloadStatus::Completed)
            ->where('id', '<', $download->id)
            ->count();

        if ($collisions === 0) {
            return $mmdd;
        }

        // Generate suffix: b, c, d, ... z, ba, bb, ... bz, ca, cb, ... zz
        $suffixes = [];
        for ($i = 0; $i < 26; $i++) {
            $suffixes[] = chr(98 + $i); // b-z
        }
        for ($i = 0; $i < 26; $i++) {
            for ($j = 0; $j < 26; $j++) {
                $suffixes[] = chr(97 + $i) . chr(97 + $j); // aa-zz
            }
        }
        return $mmdd . ($suffixes[$collisions - 1] ?? (string) $collisions);
    }

    public static function basename(Download $download): string
    {
        $channel = self::sanitize($download->channel);
        $season  = self::season($download);
        $episode = self::episode($download);
        $title   = self::sanitize($download->title);

        return "{$channel} - S{$season}E{$episode} - {$title}";
    }
}
