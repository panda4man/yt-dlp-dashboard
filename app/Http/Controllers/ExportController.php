<?php

namespace App\Http\Controllers;

use App\Enums\DownloadStatus;
use App\Jobs\ExportDownload;
use App\Models\Download;
use Illuminate\Http\JsonResponse;

class ExportController extends Controller
{
    public function reexport(Download $download): JsonResponse
    {
        if (! $download->exported_at) {
            return response()->json(['message' => 'Not yet exported.'], 422);
        }

        $download->update([
            'exported_at'       => null,
            'plex_refreshed_at' => null,
            'plex_error'        => null,
            'status'            => DownloadStatus::Exporting,
        ]);

        ExportDownload::dispatch($download);

        return response()->json(['dispatched' => true]);
    }

    public function store(Download $download): JsonResponse
    {
        if ($download->exported_at) {
            return response()->json(['message' => 'Already exported.'], 409);
        }

        if (!in_array($download->status, [DownloadStatus::Completed, DownloadStatus::ExportFailed])) {
            return response()->json(['message' => 'Download not ready for export.'], 422);
        }

        $download->update(['status' => DownloadStatus::Exporting]);
        ExportDownload::dispatch($download);

        return response()->json(['dispatched' => true]);
    }
}
