<?php

use App\Enums\DownloadStatus;
use App\Models\Download;
use App\Models\User;
use App\Services\ExportService;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('triggers export for completed download', function () {
    $download = Download::factory()->completed()->create();

    $mock = Mockery::mock(ExportService::class);
    $mock->shouldReceive('export')->once()->with(Mockery::on(
        fn ($d) => $d->id === $download->id
    ));
    app()->instance(ExportService::class, $mock);

    $this->post("/downloads/{$download->id}/export")->assertRedirect('/history');
});

it('returns 409 if download already exported', function () {
    $download = Download::factory()->completed()->create([
        'exported_at' => now(),
    ]);

    $this->postJson("/downloads/{$download->id}/export")->assertStatus(409);
});

it('returns 422 if download not yet completed', function () {
    $download = Download::factory()->create(['status' => DownloadStatus::Processing]);

    $this->postJson("/downloads/{$download->id}/export")->assertStatus(422);
});
