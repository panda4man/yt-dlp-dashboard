<?php

namespace App\Http\Controllers;

use App\Enums\DownloadStatus;
use App\Models\Download;
use App\Services\ExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class ExportController extends Controller
{
    public function __construct(private ExportService $exporter) {}

    public function store(Download $download): JsonResponse|RedirectResponse
    {
        if ($download->exported_at) {
            return response()->json(['message' => 'Already exported.'], 409);
        }

        if ($download->status !== DownloadStatus::Completed) {
            return response()->json(['message' => 'Download not complete.'], 422);
        }

        $this->exporter->export($download);

        return redirect('/history');
    }
}
