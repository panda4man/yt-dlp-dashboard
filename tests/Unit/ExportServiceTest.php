<?php

use App\Models\Download;
use App\Services\ExportService;
use App\Support\PlexNaming;

function makeSourceFiles(Download $download): void
{
    $dir = storage_path('app/private/downloads/' . $download->id);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents("{$dir}/video.mp4",     'fake video');
    file_put_contents("{$dir}/episode.nfo",   '<episodedetails/>');
    file_put_contents("{$dir}/thumbnail.jpg", 'fake thumb');
}

function rrmdir(string $dir): void
{
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = "{$dir}/{$item}";
        is_dir($path) ? rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

beforeEach(function () {
    $this->destDir = sys_get_temp_dir() . '/plex_test_' . uniqid();
    mkdir($this->destDir, 0755, true);
    config(['export.dest_path' => $this->destDir]);
});

afterEach(function () {
    if (is_dir($this->destDir)) {
        rrmdir($this->destDir);
    }
});

it('copies video file to plex episode directory with correct name', function () {
    $download = Download::factory()->completed()->create();
    makeSourceFiles($download);

    (new ExportService())->export($download);

    $channel  = PlexNaming::sanitize($download->channel);
    $season   = PlexNaming::season($download);
    $basename = PlexNaming::basename($download);

    expect(file_exists("{$this->destDir}/{$channel}/Season {$season}/{$basename}.mp4"))->toBeTrue();
});

it('copies nfo file to plex episode directory with correct name', function () {
    $download = Download::factory()->completed()->create();
    makeSourceFiles($download);

    (new ExportService())->export($download);

    $channel  = PlexNaming::sanitize($download->channel);
    $season   = PlexNaming::season($download);
    $basename = PlexNaming::basename($download);

    expect(file_exists("{$this->destDir}/{$channel}/Season {$season}/{$basename}.nfo"))->toBeTrue();
});

it('copies thumbnail to plex episode directory with correct name', function () {
    $download = Download::factory()->completed()->create();
    makeSourceFiles($download);

    (new ExportService())->export($download);

    $channel  = PlexNaming::sanitize($download->channel);
    $season   = PlexNaming::season($download);
    $basename = PlexNaming::basename($download);

    expect(file_exists("{$this->destDir}/{$channel}/Season {$season}/{$basename}-thumb.jpg"))->toBeTrue();
});

it('creates tvshow.nfo in channel directory', function () {
    $download = Download::factory()->completed()->create();
    makeSourceFiles($download);

    (new ExportService())->export($download);

    $channel = PlexNaming::sanitize($download->channel);

    expect(file_exists("{$this->destDir}/{$channel}/tvshow.nfo"))->toBeTrue();
});

it('tvshow.nfo contains channel name', function () {
    $download = Download::factory()->completed()->create(['channel' => 'Test Channel']);
    makeSourceFiles($download);

    (new ExportService())->export($download);

    $nfo = file_get_contents("{$this->destDir}/Test Channel/tvshow.nfo");
    expect($nfo)->toContain('<title>Test Channel</title>');
});

it('does not overwrite existing tvshow.nfo', function () {
    $download = Download::factory()->completed()->create(['channel' => 'Test Channel']);
    makeSourceFiles($download);

    $channelDir = "{$this->destDir}/Test Channel";
    mkdir($channelDir, 0755, true);
    file_put_contents("{$channelDir}/tvshow.nfo", 'original content');

    (new ExportService())->export($download);

    expect(file_get_contents("{$channelDir}/tvshow.nfo"))->toBe('original content');
});

it('sets exported_at on success', function () {
    $download = Download::factory()->completed()->create();
    makeSourceFiles($download);

    (new ExportService())->export($download);

    expect($download->fresh()->exported_at)->not->toBeNull();
});

it('exports nfo generated fresh from PlexNfoService', function () {
    $download = Download::factory()->completed()->create([
        'title'       => 'My Video',
        'uploaded_at' => '2024-03-15',
    ]);
    makeSourceFiles($download);

    (new ExportService())->export($download);

    $channel  = PlexNaming::sanitize($download->channel);
    $season   = PlexNaming::season($download);
    $basename = PlexNaming::basename($download);
    $nfo      = file_get_contents("{$this->destDir}/{$channel}/Season {$season}/{$basename}.nfo");

    expect($nfo)
        ->toContain('<lockdata>true</lockdata>')
        ->toContain('<aired>2024-03-15</aired>')
        ->toContain('<title>My Video</title>');
});

it('throws RuntimeException when video file is missing', function () {
    $download = Download::factory()->completed()->create();

    // source dir exists but video.mp4 does not
    $dir = storage_path('app/private/downloads/' . $download->id);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    expect(fn () => (new ExportService())->export($download))
        ->toThrow(RuntimeException::class);
});
