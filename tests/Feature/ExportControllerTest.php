<?php

use App\Enums\DownloadStatus;
use App\Jobs\ExportDownload;
use App\Models\Download;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('dispatches ExportDownload job for completed download', function () {
    Queue::fake();
    $download = Download::factory()->completed()->create();

    $this->postJson("/downloads/{$download->id}/export")
        ->assertOk()
        ->assertJson(['dispatched' => true]);

    Queue::assertPushed(ExportDownload::class, fn ($job) => $job->download->id === $download->id);
});

it('dispatches ExportDownload job for export_failed download (retry)', function () {
    Queue::fake();
    $download = Download::factory()->exportFailed()->create();

    $this->postJson("/downloads/{$download->id}/export")
        ->assertOk()
        ->assertJson(['dispatched' => true]);

    Queue::assertPushed(ExportDownload::class, fn ($job) => $job->download->id === $download->id);
});

it('sets status to exporting before dispatching job', function () {
    Queue::fake();
    $download = Download::factory()->completed()->create();

    $this->postJson("/downloads/{$download->id}/export");

    expect($download->fresh()->status)->toBe(DownloadStatus::Exporting);
});

it('returns 409 if download already exported', function () {
    $download = Download::factory()->completed()->create(['exported_at' => now()]);

    $this->postJson("/downloads/{$download->id}/export")->assertStatus(409);
});

it('returns 422 if download status is not ready for export', function () {
    foreach ([DownloadStatus::Processing, DownloadStatus::Staged, DownloadStatus::Failed] as $status) {
        $download = Download::factory()->create(['status' => $status]);

        $this->postJson("/downloads/{$download->id}/export")->assertStatus(422);
    }
});

it('reexport dispatches job and clears exported_at for already-exported download', function () {
    Queue::fake();
    $download = Download::factory()->completed()->create(['exported_at' => now()]);

    $this->postJson("/downloads/{$download->id}/reexport")
        ->assertOk()
        ->assertJson(['dispatched' => true]);

    Queue::assertPushed(ExportDownload::class, fn ($job) => $job->download->id === $download->id);
    expect($download->fresh()->exported_at)->toBeNull();
});

it('reexport sets status to exporting and clears plex fields', function () {
    Queue::fake();
    $download = Download::factory()->completed()->create([
        'exported_at'       => now(),
        'plex_refreshed_at' => now(),
        'plex_error'        => 'some plex error',
    ]);

    $this->postJson("/downloads/{$download->id}/reexport");

    $fresh = $download->fresh();
    expect($fresh->status)->toBe(DownloadStatus::Exporting)
        ->and($fresh->plex_refreshed_at)->toBeNull()
        ->and($fresh->plex_error)->toBeNull();
});

it('reexport returns 422 for download not yet exported', function () {
    $download = Download::factory()->completed()->create(['exported_at' => null]);

    $this->postJson("/downloads/{$download->id}/reexport")->assertStatus(422);
});
