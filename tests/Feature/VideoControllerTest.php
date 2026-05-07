<?php

use App\Enums\DownloadStatus;
use App\Jobs\ProcessDownload;
use App\Models\Download;
use App\Models\User;
use App\Services\YtDlpService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders dashboard page', function () {
    $this->get('/')->assertStatus(200);
});

it('returns video preview metadata', function () {
    $mock = Mockery::mock(YtDlpService::class);
    $mock->shouldReceive('getMetadata')
        ->with('https://youtube.com/watch?v=abc123')
        ->andReturn([
            'title'     => 'My Video',
            'channel'   => 'My Channel',
            'duration'  => 300,
            'thumbnail' => 'https://i.ytimg.com/vi/abc/default.jpg',
        ]);
    app()->instance(YtDlpService::class, $mock);

    $this->postJson('/videos/preview', ['url' => 'https://youtube.com/watch?v=abc123'])
        ->assertOk()
        ->assertJson(['title' => 'My Video', 'channel' => 'My Channel', 'duration' => 300]);
});

it('returns 422 for invalid preview url', function () {
    $this->postJson('/videos/preview', ['url' => 'not-a-url'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');
});

it('creates download record and dispatches job', function () {
    Queue::fake();

    $mock = Mockery::mock(YtDlpService::class);
    $mock->shouldReceive('getMetadata')->andReturn([
        'title' => 'My Video', 'channel' => 'My Channel',
        'duration' => 300, 'thumbnail' => 'https://i.ytimg.com/vi/abc/default.jpg',
    ]);
    app()->instance(YtDlpService::class, $mock);

    $this->post('/videos', ['url' => 'https://youtube.com/watch?v=abc123'])
        ->assertRedirect('/');

    Queue::assertPushed(ProcessDownload::class);
    $this->assertDatabaseHas('downloads', [
        'youtube_url' => 'https://youtube.com/watch?v=abc123',
        'status'      => DownloadStatus::Pending->value,
    ]);
});

it('blocks submit when video is already queued', function () {
    Download::factory()->create([
        'youtube_url' => 'https://youtube.com/watch?v=abc123',
        'status'      => DownloadStatus::Processing,
    ]);

    $this->postJson('/videos', ['url' => 'https://youtube.com/watch?v=abc123'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');
});

it('returns 409 when video already completed', function () {
    Download::factory()->completed()->create([
        'youtube_url' => 'https://youtube.com/watch?v=abc123',
    ]);

    $this->postJson('/videos', ['url' => 'https://youtube.com/watch?v=abc123'])
        ->assertStatus(409)
        ->assertJson(['already_downloaded' => true]);
});

it('allows force re-download of completed video', function () {
    Queue::fake();

    Download::factory()->completed()->create([
        'youtube_url' => 'https://youtube.com/watch?v=abc123',
    ]);

    $mock = Mockery::mock(YtDlpService::class);
    $mock->shouldReceive('getMetadata')->andReturn([
        'title' => 'My Video', 'channel' => 'My Channel',
        'duration' => 300, 'thumbnail' => 'https://i.ytimg.com/vi/abc/default.jpg',
    ]);
    app()->instance(YtDlpService::class, $mock);

    $this->post('/videos', ['url' => 'https://youtube.com/watch?v=abc123', 'force' => true])
        ->assertRedirect('/');

    Queue::assertPushed(ProcessDownload::class);
});

it('allows re-queuing a failed download without force flag', function () {
    Queue::fake();

    Download::factory()->create([
        'youtube_url' => 'https://youtube.com/watch?v=abc123',
        'status'      => DownloadStatus::Failed,
    ]);

    $mock = Mockery::mock(YtDlpService::class);
    $mock->shouldReceive('getMetadata')->andReturn([
        'title' => 'My Video', 'channel' => 'My Channel',
        'duration' => 300, 'thumbnail' => 'https://i.ytimg.com/vi/abc/default.jpg',
    ]);
    app()->instance(YtDlpService::class, $mock);

    $this->post('/videos', ['url' => 'https://youtube.com/watch?v=abc123'])
        ->assertRedirect('/');

    Queue::assertPushed(ProcessDownload::class);
});

it('returns only pending and processing downloads for queue poll', function () {
    Download::factory()->create(['status' => DownloadStatus::Pending]);
    Download::factory()->create(['status' => DownloadStatus::Processing]);
    Download::factory()->completed()->create();

    $this->getJson('/api/queue')
        ->assertOk()
        ->assertJsonCount(2);
});
