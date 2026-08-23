<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Controllers\Api\V1\Developer\AnalyticsController;
use App\Http\Controllers\Api\V1\Developer\ApiTokenController;
use App\Http\Controllers\Api\V1\Developer\LeadController as DeveloperLeadController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Middleware\EnsureTenantApiAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->middleware('throttle:5,1')->group(function (): void {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

Route::middleware('throttle:60,1')->group(function (): void {
    Route::post('v1/webhooks/telegram/{tenant_id}', TelegramWebhookController::class)
        ->name('webhooks.telegram');

    Route::post('v1/webhooks/meta/{tenant_id}', WebhookController::class)
        ->defaults('source', 'meta')
        ->name('webhooks.meta');

    Route::post('v1/webhooks/tiktok/{tenant_id}', WebhookController::class)
        ->defaults('source', 'tiktok')
        ->name('webhooks.tiktok');

    Route::post('v1/webhooks/google/{tenant_id}', WebhookController::class)
        ->defaults('source', 'google')
        ->name('webhooks.google');

    Route::post('v1/webhooks/snapchat/{tenant_id}', WebhookController::class)
        ->defaults('source', 'snapchat')
        ->name('webhooks.snapchat');

    Route::post('v1/webhooks/universal/{tenant_id}', WebhookController::class)
        ->defaults('source', 'universal')
        ->name('webhooks.universal');

    Route::post('v1/webhooks/website/{tenant_id}', WebhookController::class)
        ->defaults('source', 'website')
        ->name('webhooks.website');

    Route::post('v1/webhooks/{source}/{tenant_id}', WebhookController::class)
        ->name('webhooks.dispatch');
});

Route::post('v1/payments/webhook/{driver}', PaymentWebhookController::class)
    ->middleware('throttle:60,1');

Route::prefix('v1/developer')
    ->middleware(['auth:sanctum', 'abilities:developer:*', EnsureTenantApiAccess::class])
    ->group(function (): void {
        Route::get('leads', [DeveloperLeadController::class, 'index']);
        Route::get('leads/{id}', [DeveloperLeadController::class, 'show']);
        Route::get('analytics/sla-summary', [AnalyticsController::class, 'slaSummary']);
        Route::post('tokens', [ApiTokenController::class, 'store']);
        Route::delete('tokens/{tokenId}', [ApiTokenController::class, 'destroy']);
    });
