<?php

namespace App\Services;

use App\Models\Download;
use App\Support\PlexNaming;

class PlexNfoService
{
    public function episodeNfo(Download $download): string
    {
        $season  = PlexNaming::season($download);
        $episode = (int) PlexNaming::episode($download); // (int) strips any b/c suffix
        $title   = htmlspecialchars($download->title, ENT_XML1);
        $channel = htmlspecialchars($download->channel, ENT_XML1);
        $plot    = htmlspecialchars($download->description ?? '', ENT_XML1);
        $videoId = htmlspecialchars($download->youtube_video_id ?? '', ENT_XML1);

        $lines = [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<episodedetails>',
            "  <title>{$title}</title>",
            "  <showtitle>{$channel}</showtitle>",
            "  <season>{$season}</season>",
            "  <episode>{$episode}</episode>",
            "  <plot>{$plot}</plot>",
        ];

        if ($download->uploaded_at) {
            $lines[] = "  <premiered>{$download->uploaded_at->format('Y-m-d')}</premiered>";
        }

        array_push($lines,
            "  <studio>{$channel}</studio>",
            '  <thumb>thumbnail.jpg</thumb>',
            "  <uniqueid type=\"youtube\">{$videoId}</uniqueid>",
            '</episodedetails>',
        );

        return implode("\n", $lines) . "\n";
    }

    public function showNfo(Download $download): string
    {
        $channel = htmlspecialchars($download->channel, ENT_XML1);

        return implode("\n", [
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<tvshow>',
            "  <title>{$channel}</title>",
            '</tvshow>',
        ]) . "\n";
    }
}
