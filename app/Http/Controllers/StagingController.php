<?php

namespace App\Http\Controllers;

use App\Enums\DownloadStatus;
use App\Models\Download;
use App\Services\PlexNfoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class StagingController extends Controller
{
    public function index(): Response
    {
        $downloads = Download::where('status', DownloadStatus::Staged)
            ->orderByDesc('created_at')
            ->get([
                'id', 'title', 'channel', 'duration_seconds',
                'thumbnail_path', 'uploaded_at', 'description', 'status', 'created_at',
            ]);

        return Inertia::render('Staging', ['downloads' => $downloads]);
    }

    public function update(Request $request, Download $download): JsonResponse
    {
        $data = $request->validate([
            'channel'     => ['required', 'string', 'max:255'],
            'title'       => ['required', 'string', 'max:255'],
            'uploaded_at' => ['required', 'date'],
            'description' => ['nullable', 'string'],
        ]);

        $download->update($data);
        $this->regenerateNfo($download); // calls $download->refresh() internally

        return response()->json($download);
    }

    public function approve(Request $request, Download $download): JsonResponse
    {
        $data = $request->validate([
            'channel'     => ['required', 'string', 'max:255'],
            'title'       => ['required', 'string', 'max:255'],
            'uploaded_at' => ['required', 'date'],
            'description' => ['nullable', 'string'],
        ]);

        $download->update([...$data, 'status' => DownloadStatus::Completed]);
        $this->regenerateNfo($download);

        return response()->json(['approved' => true]);
    }

    private function regenerateNfo(Download $download): void
    {
        $download->refresh();
        $nfo  = app(PlexNfoService::class)->episodeNfo($download);
        $path = 'downloads/' . $download->id . '/episode.nfo';
        Storage::disk('local')->put($path, $nfo);
    }
}
