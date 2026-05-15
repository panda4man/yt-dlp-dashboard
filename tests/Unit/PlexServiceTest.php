<?php

use App\Services\PlexService;
use Illuminate\Support\Facades\Http;

it('calls plex refresh endpoint with token', function () {
    Http::fake(['*/library/sections/all/refresh*' => Http::response('', 200)]);

    config(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'mytoken']);

    (new PlexService())->refreshLibrary();

    Http::assertSent(fn ($request) =>
        str_contains($request->url(), '/library/sections/all/refresh') &&
        $request['X-Plex-Token'] === 'mytoken'
    );
});

it('throws RuntimeException when plex responds with non-2xx', function () {
    Http::fake(['*' => Http::response('Unauthorized', 401)]);

    config(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'bad-token']);

    expect(fn () => (new PlexService())->refreshLibrary())
        ->toThrow(RuntimeException::class);
});

it('throws RuntimeException when plex url not configured', function () {
    config(['plex.url' => null, 'plex.token' => 'mytoken']);

    expect(fn () => (new PlexService())->refreshLibrary())
        ->toThrow(RuntimeException::class, 'Plex URL or token not configured');
});

it('throws RuntimeException when plex token not configured', function () {
    config(['plex.url' => 'http://plex.local:32400', 'plex.token' => null]);

    expect(fn () => (new PlexService())->refreshLibrary())
        ->toThrow(RuntimeException::class, 'Plex URL or token not configured');
});
