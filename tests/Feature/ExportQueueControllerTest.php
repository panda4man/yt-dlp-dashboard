<?php

use App\Enums\DownloadStatus;
use App\Models\Download;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders export queue page', function () {
    $this->get('/export-queue')->assertOk();
});

it('poll returns json with four groups', function () {
    $this->getJson('/api/export-queue')
        ->assertOk()
        ->assertJsonStructure(['waiting', 'exporting', 'failed', 'recent']);
});

it('waiting group contains completed downloads without exported_at', function () {
    $waiting  = Download::factory()->completed()->create();
    $exported = Download::factory()->completed()->create(['exported_at' => now()]);

    $data = $this->getJson('/api/export-queue')->json();

    $ids = collect($data['waiting'])->pluck('id')->all();
    expect($ids)->toContain($waiting->id)
        ->not->toContain($exported->id);
});

it('exporting group contains downloads with exporting status', function () {
    $exporting = Download::factory()->exporting()->create();

    $data = $this->getJson('/api/export-queue')->json();

    expect(collect($data['exporting'])->pluck('id')->all())
        ->toContain($exporting->id);
});

it('failed group contains downloads with export_failed status', function () {
    $failed = Download::factory()->exportFailed()->create();

    $data = $this->getJson('/api/export-queue')->json();

    expect(collect($data['failed'])->pluck('id')->all())
        ->toContain($failed->id);
});

it('recent group contains downloads exported in last 24h', function () {
    $recent = Download::factory()->completed()->create(['exported_at' => now()->subHours(2)]);
    $old    = Download::factory()->completed()->create(['exported_at' => now()->subHours(25)]);

    $data = $this->getJson('/api/export-queue')->json();

    $ids = collect($data['recent'])->pluck('id')->all();
    expect($ids)->toContain($recent->id)
        ->not->toContain($old->id);
});

it('waiting group excludes exporting and export_failed downloads', function () {
    $exporting = Download::factory()->exporting()->create();
    $failed    = Download::factory()->exportFailed()->create();

    $data = $this->getJson('/api/export-queue')->json();

    $waitingIds = collect($data['waiting'])->pluck('id')->all();
    expect($waitingIds)
        ->not->toContain($exporting->id)
        ->not->toContain($failed->id);
});
