<?php

namespace App\Http\Controllers;

use App\Enums\DownloadStatus;
use App\Models\Download;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class ExportQueueController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('ExportQueue', $this->data());
    }

    public function poll(): JsonResponse
    {
        return response()->json($this->data());
    }

    private function data(): array
    {
        $fields = ['id', 'title', 'channel', 'duration_seconds', 'status',
                   'file_size_bytes', 'thumbnail_path', 'exported_at',
                   'export_error', 'plex_refreshed_at', 'plex_error'];

        return [
            'waiting'   => Download::where('status', DownloadStatus::Completed)
                ->whereNull('exported_at')
                ->orderBy('completed_at')
                ->get($fields),

            'exporting' => Download::where('status', DownloadStatus::Exporting)
                ->orderBy('updated_at')
                ->get($fields),

            'failed'    => Download::where('status', DownloadStatus::ExportFailed)
                ->orderBy('updated_at', 'desc')
                ->get($fields),

            'recent'    => Download::where('status', DownloadStatus::Completed)
                ->whereNotNull('exported_at')
                ->where('exported_at', '>=', now()->subDay())
                ->orderBy('exported_at', 'desc')
                ->get($fields),
        ];
    }
}
