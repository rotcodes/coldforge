<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ComposeController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CsvImportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FallbacksController;
use App\Http\Controllers\IndustryController;
use App\Http\Controllers\IntelligenceController;
use App\Http\Controllers\MailAttachmentController;
use App\Http\Controllers\MailboxSettingsController;
use App\Http\Controllers\MailController;
use App\Http\Controllers\SequenceController;
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
    Route::get('/analytics', AnalyticsController::class);
    Route::get('/activity', ActivityController::class);
    Route::get('/intelligence', [IntelligenceController::class, 'index']);
    Route::post('/intelligence/conversations', [IntelligenceController::class, 'create']);
    Route::post('/intelligence/conversations/{conversation}/chat', [IntelligenceController::class, 'chat'])->middleware('throttle:ai');
    Route::patch('/intelligence/conversations/{conversation}/mode', [IntelligenceController::class, 'mode']);
    Route::post('/intelligence/actions/{action}', [IntelligenceController::class, 'action']);
    Route::delete('/intelligence/conversations/{conversation}', [IntelligenceController::class, 'destroy']);

    Route::resource('contacts', ContactController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::post('/contacts/{contact}/sequences/{enrollment}/status', [ContactController::class, 'sequenceStatus']);
    Route::post('/csv-imports', [CsvImportController::class, 'store']);
    Route::post('/csv-imports/{import}/start', [CsvImportController::class, 'start']);
    Route::get('/api/csv-imports/{import}', [CsvImportController::class, 'show']);

    Route::resource('templates', TemplateController::class)->except('show');
    Route::post('/templates/{template}/test', [TemplateController::class, 'test']);

    Route::get('/campaigns/count', [CampaignController::class, 'count']);
    Route::post('/campaigns/preview', [CampaignController::class, 'preview']);
    Route::resource('campaigns', CampaignController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
    Route::post('/campaigns/{campaign}/launch', [CampaignController::class, 'launch']);
    Route::post('/campaigns/{campaign}/pause', [CampaignController::class, 'pause']);
    Route::post('/campaigns/{campaign}/resume', [CampaignController::class, 'resume']);
    Route::post('/campaigns/{campaign}/cancel', [CampaignController::class, 'cancel']);

    Route::get('/sequences', [SequenceController::class, 'index']);
    Route::post('/sequences', [SequenceController::class, 'store']);
    Route::post('/sequences/{sequence}/status', [SequenceController::class, 'status']);

    Route::redirect('/inbox', '/mail');
    Route::get('/mail', [MailController::class, 'index']);
    Route::post('/mail/sync', [MailController::class, 'sync']);
    Route::post('/mail/messages/{message}/action', [MailController::class, 'action']);
    Route::post('/mail/messages/bulk-action', [MailController::class, 'bulkAction']);
    Route::post('/mail/messages/{message}/ai', [AiController::class, 'assist']);
    Route::put('/mail/messages/{message}/ai-classification', [AiController::class, 'correct']);
    Route::get('/mail/compose', [ComposeController::class, 'create']);
    Route::get('/mail/messages/{message}/edit', [ComposeController::class, 'edit']);
    Route::post('/mail/messages', [ComposeController::class, 'store']);
    Route::post('/mail/messages/{message}/cancel', [ComposeController::class, 'cancel']);
    Route::post('/mail/messages/{message}/retry', [ComposeController::class, 'retry']);
    Route::get('/mail/attachments/{attachment}/download', [MailAttachmentController::class, 'download']);
    Route::get('/mail/attachments/{attachment}/preview', [MailAttachmentController::class, 'preview']);
    Route::get('/exports/contacts', [ExportController::class, 'contacts']);
    Route::get('/exports/campaigns/{campaign}', [ExportController::class, 'campaign']);
    Route::get('/exports/suppressions', [ExportController::class, 'suppressions']);

    Route::get('/industries', [IndustryController::class, 'index']);
    Route::post('/industries', [IndustryController::class, 'store']);
    Route::put('/industries/{industry}', [IndustryController::class, 'update']);
    Route::delete('/industries/{industry}', [IndustryController::class, 'destroy']);
    Route::post('/industries/rematch', [IndustryController::class, 'rematch']);
    Route::post('/industries/assign-unmapped', [IndustryController::class, 'assignUnmapped']);
    Route::get('/industries/suggest', [IndustryController::class, 'suggest']);
    Route::post('/industries/accept-suggestions', [IndustryController::class, 'acceptSuggestions']);

    Route::prefix('settings')->group(function () {
        Route::redirect('/', '/settings/mailbox');
        Route::redirect('/senders', '/settings/mailbox');
        Route::get('/mailbox', [MailboxSettingsController::class, 'index']);
        Route::put('/mailbox', [MailboxSettingsController::class, 'update']);
        Route::post('/mailbox/test', [MailboxSettingsController::class, 'test']);

        Route::get('/profile', [SettingsController::class, 'profile']);
        Route::put('/profile', [SettingsController::class, 'updateProfile']);
        Route::put('/password', [SettingsController::class, 'updatePassword']);

        Route::get('/deliverability', [SettingsController::class, 'deliverability']);
        Route::get('/retention', [SettingsController::class, 'retention']);
        Route::put('/retention', [SettingsController::class, 'updateRetention']);

        Route::get('/fallbacks', [FallbacksController::class, 'index']);
        Route::put('/fallbacks', [FallbacksController::class, 'update']);
    });
});
