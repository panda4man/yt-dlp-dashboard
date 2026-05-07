<?php

use App\Enums\DownloadStatus;
use App\Models\Download;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders history page with downloads', function () {
    Download::factory()->count(3)->create();

    $this->get('/history')->assertStatus(200);
});

it('deletes download record and removes directory from disk', function () {
    $download = Download::factory()->completed()->create();

    $dir = storage_path('app/private/downloads/' . $download->id);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($dir . '/video.mp4', 'fake');

    $this->delete("/downloads/{$download->id}")->assertRedirect('/history');

    $this->assertDatabaseMissing('downloads', ['id' => $download->id]);
    expect(is_dir($dir))->toBeFalse();
});

it('serves thumbnail from private storage', function () {
    $download = Download::factory()->completed()->create([
        'thumbnail_path' => 'downloads/' . 1 . '/thumbnail.jpg',
    ]);

    $dir = storage_path('app/private/downloads/' . $download->id);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($dir . '/thumbnail.jpg', 'fake-img');

    $this->get("/downloads/{$download->id}/thumbnail")->assertOk();

    // Cleanup
    foreach (glob($dir . '/*') as $file) {
        unlink($file);
    }
    rmdir($dir);
});

it('redirects unauthenticated user from history', function () {
    auth()->logout();
    $this->get('/history')->assertRedirect('/login');
});
