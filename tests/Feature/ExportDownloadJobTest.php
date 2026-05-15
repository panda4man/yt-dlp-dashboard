<?php

use App\Enums\DownloadStatus;
use App\Jobs\ExportDownload;
use App\Models\Download;
use App\Services\ExportService;
use App\Services\PlexService;

it('sets status to exporting then completed after successful rsync and plex refresh', function () {
    $download = Download::factory()->completed()->create();

    $exporter = Mockery::mock(ExportService::class);
    $exporter->shouldReceive('export')->once()->andReturnUsing(function ($d) {
        $d->update(['exported_at' => now()]);
    });

    $plex = Mockery::mock(PlexService::class);
    $plex->shouldReceive('refreshLibrary')->once();

    (new ExportDownload($download))->handle($exporter, $plex);

    $download->refresh();

    expect($download->status)->toBe(DownloadStatus::Completed)
        ->and($download->exported_at)->not->toBeNull()
        ->and($download->plex_refreshed_at)->not->toBeNull()
        ->and($download->export_error)->toBeNull();
});

it('sets status to export_failed and stores error when rsync fails', function () {
    $download = Download::factory()->completed()->create();

    $exporter = Mockery::mock(ExportService::class);
    $exporter->shouldReceive('export')->once()->andThrow(new RuntimeException('Connection refused'));

    $plex = Mockery::mock(PlexService::class);
    $plex->shouldNotReceive('refreshLibrary');

    expect(fn () => (new ExportDownload($download))->handle($exporter, $plex))
        ->toThrow(RuntimeException::class, 'Connection refused');

    $download->refresh();

    expect($download->status)->toBe(DownloadStatus::ExportFailed)
        ->and($download->export_error)->toBe('Connection refused')
        ->and($download->exported_at)->toBeNull();
});

it('stores plex_error without changing export status when plex refresh fails', function () {
    $download = Download::factory()->completed()->create();

    $exporter = Mockery::mock(ExportService::class);
    $exporter->shouldReceive('export')->once()->andReturnUsing(function ($d) {
        $d->update(['exported_at' => now()]);
    });

    $plex = Mockery::mock(PlexService::class);
    $plex->shouldReceive('refreshLibrary')->once()->andThrow(new RuntimeException('Plex unreachable'));

    (new ExportDownload($download))->handle($exporter, $plex);

    $download->refresh();

    expect($download->status)->toBe(DownloadStatus::Completed)
        ->and($download->exported_at)->not->toBeNull()
        ->and($download->plex_error)->toBe('Plex unreachable')
        ->and($download->plex_refreshed_at)->toBeNull();
});

it('sets status to exporting at start of job', function () {
    $download = Download::factory()->completed()->create();

    $exporter = Mockery::mock(ExportService::class);
    $exporter->shouldReceive('export')->once()->andReturnUsing(function ($d) use ($download) {
        $download->refresh();
        expect($download->status)->toBe(DownloadStatus::Exporting);
        $d->update(['exported_at' => now()]);
    });

    $plex = Mockery::mock(PlexService::class);
    $plex->shouldReceive('refreshLibrary')->once();

    (new ExportDownload($download))->handle($exporter, $plex);
});

it('marks download as export_failed via failed() backstop', function () {
    $download = Download::factory()->completed()->create();

    $job = new ExportDownload($download);
    $job->failed(new RuntimeException('Queue timeout'));

    $download->refresh();

    expect($download->status)->toBe(DownloadStatus::ExportFailed)
        ->and($download->export_error)->toBe('Queue timeout');
});

it('failed() backstop does not overwrite already set export_failed state', function () {
    $download = Download::factory()->exportFailed()->create([
        'export_error' => 'original rsync error',
    ]);

    $job = new ExportDownload($download);
    $job->failed(new RuntimeException('Queue timeout'));

    $download->refresh();

    expect($download->export_error)->toBe('original rsync error');
});
