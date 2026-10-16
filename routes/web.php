<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CsvImportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SenderController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

// Public tracking endpoints (signed URLs, embedded in sent emails)
Route::middleware('signed')->group(function () {
    Route::get('/t/o/{send}', [TrackingController::class, 'open'])->name('track.open');
    Route::get('/t/c/{send}', [TrackingController::class, 'click'])->name('track.click');
    Route::match(['get', 'post'], '/u/{contact}', [TrackingController::class, 'unsubscribe'])->name('unsubscribe');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy']);
    Route::get('/', DashboardController::class);

    Route::resource('contacts', ContactController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::post('/csv-imports', [CsvImportController::class, 'store']);
    Route::post('/csv-imports/{import}/start', [CsvImportController::class, 'start']);
    Route::get('/api/csv-imports/{import}', [CsvImportController::class, 'show']);

    Route::resource('templates', TemplateController::class)->except('show');
    Route::post('/templates/{template}/test', [TemplateController::class, 'test']);

    Route::get('/campaigns/count', [CampaignController::class, 'count']);
    Route::resource('campaigns', CampaignController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
    Route::post('/campaigns/{campaign}/launch', [CampaignController::class, 'launch']);
    Route::post('/campaigns/{campaign}/pause', [CampaignController::class, 'pause']);
    Route::post('/campaigns/{campaign}/resume', [CampaignController::class, 'resume']);

    Route::prefix('settings')->group(function () {
        Route::redirect('/', '/settings/senders');
        Route::get('/senders', [SenderController::class, 'index']);
        Route::post('/senders', [SenderController::class, 'store']);
        Route::put('/senders/{sender}', [SenderController::class, 'update']);
        Route::delete('/senders/{sender}', [SenderController::class, 'destroy']);
        Route::post('/senders/{sender}/toggle', [SenderController::class, 'toggle']);
        Route::post('/senders/{sender}/test', [SenderController::class, 'test']);

        Route::get('/profile', [SettingsController::class, 'profile']);
        Route::put('/profile', [SettingsController::class, 'updateProfile']);
        Route::put('/password', [SettingsController::class, 'updatePassword']);

        Route::get('/deliverability', [SettingsController::class, 'deliverability']);
    });
});
