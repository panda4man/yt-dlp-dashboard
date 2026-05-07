<?php

use App\Enums\DownloadStatus;
use App\Models\Download;
use App\Support\PlexNaming;

it('strips illegal filesystem characters', function () {
    expect(PlexNaming::sanitize('Channel: "Best/Videos*Now"'))
        ->toBe('Channel BestVideosNow');
});

it('trims leading and trailing whitespace', function () {
    expect(PlexNaming::sanitize('  My Channel  '))->toBe('My Channel');
});

it('returns upload year as season', function () {
    $download = Download::factory()->create(['uploaded_at' => '2024-03-15']);
    expect(PlexNaming::season($download))->toBe(2024);
});

it('falls back to created_at year when uploaded_at is null', function () {
    $download = Download::factory()->create(['uploaded_at' => null]);
    expect(PlexNaming::season($download))->toBe((int) $download->created_at->format('Y'));
});

it('returns MMDD as episode string', function () {
    $download = Download::factory()->completed()->create([
        'channel'     => 'Solo Channel',
        'uploaded_at' => '2024-03-15',
    ]);
    expect(PlexNaming::episode($download))->toBe('0315');
});

it('appends b suffix on same-channel same-day collision', function () {
    Download::factory()->completed()->create([
        'channel'     => 'My Channel',
        'uploaded_at' => '2024-03-15',
    ]);
    $second = Download::factory()->completed()->create([
        'channel'     => 'My Channel',
        'uploaded_at' => '2024-03-15',
    ]);
    expect(PlexNaming::episode($second))->toBe('0315b');
});

it('does not count failed or pending downloads as collisions', function () {
    Download::factory()->create([
        'channel'     => 'My Channel',
        'uploaded_at' => '2024-03-15',
        'status'      => DownloadStatus::Failed,
    ]);
    $download = Download::factory()->completed()->create([
        'channel'     => 'My Channel',
        'uploaded_at' => '2024-03-15',
    ]);
    expect(PlexNaming::episode($download))->toBe('0315');
});

it('generates basename from download', function () {
    $download = Download::factory()->completed()->create([
        'title'       => 'My Video',
        'channel'     => 'My Channel',
        'uploaded_at' => '2024-03-15',
    ]);
    expect(PlexNaming::basename($download))->toBe('My Channel - S2024E0315 - My Video');
});

it('returns fallback episode 0101 when uploaded_at is null', function () {
    $download = Download::factory()->completed()->create(['uploaded_at' => null]);
    expect(PlexNaming::episode($download))->toBe('0101');
});
