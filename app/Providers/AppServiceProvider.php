<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        URL::forceRootUrl(config('app.url'));

        Horizon::auth(function ($request) {
            return auth()->check();
        });
    }
}
