<?php

namespace App\Http\Controllers;

use App\Enums\DownloadStatus;
use App\Models\Download;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadController extends Controller
{
    public function index(): Response
    {
        $downloads = Download::where('status', '!=', DownloadStatus::Staged->value)
            ->orderByDesc('created_at')
            ->get([
                'id', 'title', 'channel', 'duration_seconds', 'thumbnail_path',
                'status', 'file_size_bytes', 'download_speed_bps',
                'completed_at', 'exported_at', 'error_message', 'created_at',
            ]);

        return Inertia::render('History', ['downloads' => $downloads]);
    }

    public function thumbnail(Download $download): BinaryFileResponse|HttpResponse
    {
        $path = storage_path('app/private/downloads/' . $download->id . '/thumbnail.jpg');
        abort_unless(file_exists($path), 404);

        return response()->file($path, ['Content-Type' => 'image/jpeg']);
    }

    public function destroy(Download $download): RedirectResponse
    {
        $dir = storage_path('app/private/downloads/' . $download->id);

        if (is_dir($dir)) {
            array_map('unlink', glob($dir . '/*'));
            rmdir($dir);
        }

        $download->delete();

        return redirect('/history');
    }
}
