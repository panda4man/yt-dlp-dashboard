<?php

use App\Enums\DownloadStatus;
use App\Models\Download;
use App\Services\ExportService;
use Illuminate\Support\Facades\Process;

it('runs rsync and sets exported_at on success', function () {
    Process::fake(['*rsync*' => Process::result(output: '', exitCode: 0)]);

    config([
        'export.rsync_host'     => 'unraid.local',
        'export.rsync_user'     => 'root',
        'export.rsync_dest'     => '/mnt/media/ytdlp',
        'export.rsync_key_path' => '/run/secrets/id_rsa',
    ]);

    $download = Download::factory()->completed()->create();

    (new ExportService())->export($download);

    $download->refresh();

    expect($download->exported_at)->not->toBeNull();

    Process::assertRan(fn ($process) => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : $process->command,
        'rsync'
    ));
});

it('throws RuntimeException when rsync fails', function () {
    Process::fake(['*rsync*' => Process::result(output: '', errorOutput: 'Connection refused', exitCode: 255)]);

    config([
        'export.rsync_host'     => 'unraid.local',
        'export.rsync_user'     => 'root',
        'export.rsync_dest'     => '/mnt/media/ytdlp',
        'export.rsync_key_path' => '/run/secrets/id_rsa',
    ]);

    $download = Download::factory()->completed()->create();

    expect(fn () => (new ExportService())->export($download))
        ->toThrow(RuntimeException::class, 'Connection refused');
});
