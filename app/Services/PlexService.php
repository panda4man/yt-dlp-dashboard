<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class PlexService
{
    public function refreshLibrary(): void
    {
        $url   = config('plex.url');
        $token = config('plex.token');

        if (!$url || !$token) {
            throw new RuntimeException('Plex URL or token not configured.');
        }

        $response = Http::get("{$url}/library/sections/all/refresh", [
            'X-Plex-Token' => $token,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Plex library refresh failed: ' . $response->status());
        }
    }
}
