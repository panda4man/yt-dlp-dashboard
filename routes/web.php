<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\StagingController;
use App\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', [VideoController::class, 'index'])->name('dashboard');
    Route::post('/videos/preview', [VideoController::class, 'preview']);
    Route::post('/videos', [VideoController::class, 'store']);
    Route::get('/api/queue', [VideoController::class, 'queue']);

    Route::get('/history', [DownloadController::class, 'index'])->name('history');
    Route::get('/downloads/{download}/thumbnail', [DownloadController::class, 'thumbnail']);
    Route::delete('/downloads/{download}', [DownloadController::class, 'destroy']);

    Route::post('/downloads/{download}/export', [ExportController::class, 'store']);

    Route::get('/staging', [StagingController::class, 'index'])->name('staging');
    Route::put('/staging/{download}', [StagingController::class, 'update']);
    Route::post('/staging/{download}/approve', [StagingController::class, 'approve']);
});
