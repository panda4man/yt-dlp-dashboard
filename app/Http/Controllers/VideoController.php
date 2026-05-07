<?php

namespace App\Http\Controllers;

use App\Enums\DownloadStatus;
use App\Jobs\ProcessDownload;
use App\Models\Download;
use App\Services\YtDlpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VideoController extends Controller
{
    public function __construct(private YtDlpService $ytDlp) {}

    public function index(): Response
    {
        return Inertia::render('Dashboard');
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate(['url' => ['required', 'url']]);

        return response()->json($this->ytDlp->getMetadata($request->url));
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate(['url' => ['required', 'url']]);

        $url      = $request->url;
        $existing = Download::where('youtube_url', $url)->latest()->first();

        if ($existing) {
            if (in_array($existing->status, [DownloadStatus::Pending, DownloadStatus::Processing])) {
                return response()->json(
                    ['errors' => ['url' => ['This video is already in the queue.']]],
                    422
                );
            }

            if ($existing->status === DownloadStatus::Completed && !$request->boolean('force')) {
                return response()->json(['already_downloaded' => true], 409);
            }
        }

        $metadata = $this->ytDlp->getMetadata($url);

        $download = Download::create([
            'youtube_url'      => $url,
            'title'            => $metadata['title'],
            'channel'          => $metadata['channel'],
            'duration_seconds' => $metadata['duration'],
            'thumbnail_url'    => $metadata['thumbnail'],
            'youtube_video_id' => $metadata['id'] ?? null,
            'uploaded_at'      => $metadata['uploaded_at'] ?? null,
            'description'      => $metadata['description'] ?? null,
            'status'           => DownloadStatus::Pending,
        ]);

        ProcessDownload::dispatch($download);

        return redirect('/');
    }

    public function queue(): JsonResponse
    {
        return response()->json(
            Download::whereIn('status', [DownloadStatus::Pending, DownloadStatus::Processing])
                ->orderBy('created_at')
                ->get(['id', 'title', 'channel', 'duration_seconds', 'status', 'created_at'])
        );
    }
}
