<?php

use App\Enums\DownloadStatus;
use App\Models\Download;
use App\Services\PlexNfoService;

it('generates valid episode NFO XML', function () {
    $download = Download::factory()->completed()->create([
        'title'            => 'My Video',
        'channel'          => 'My Channel',
        'uploaded_at'      => '2024-03-15',
        'description'      => 'A great video.',
        'youtube_video_id' => 'abc123',
    ]);

    $xml = (new PlexNfoService())->episodeNfo($download);

    expect($xml)->toContain('<title>My Video</title>')
        ->toContain('<showtitle>My Channel</showtitle>')
        ->toContain('<season>2024</season>')
        ->toContain('<episode>315</episode>')
        ->toContain('<plot>A great video.</plot>')
        ->toContain('<premiered>2024-03-15</premiered>')
        ->toContain('<studio>My Channel</studio>')
        ->toContain('<thumb>thumbnail.jpg</thumb>')
        ->toContain('<uniqueid type="youtube">abc123</uniqueid>');
});

it('omits premiered tag when uploaded_at is null', function () {
    $download = Download::factory()->completed()->create([
        'uploaded_at' => null,
    ]);

    $xml = (new PlexNfoService())->episodeNfo($download);

    expect($xml)->not->toContain('<premiered>');
});

it('generates valid show NFO XML', function () {
    $download = Download::factory()->create(['channel' => 'My Channel']);

    $xml = (new PlexNfoService())->showNfo($download);

    expect($xml)->toContain('<title>My Channel</title>');
    expect($xml)->toStartWith('<?xml');
});
