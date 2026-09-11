<?php

use Illuminate\Support\Facades\Route;
use Karsjen\StatelessQueue\Http\Controllers\StatelessQueueController;

/**
 * Stateless Queue API Routes
 * 
 * Registers the webhook route for the stateless queue.
 * 
 * @see \Karsjen\StatelessQueue\Http\Controllers\StatelessQueueController
 * @see \Karsjen\StatelessQueue\Http\Middleware\VerifyWebhookSignature
 */
Route::prefix('api')->middleware('api')->group(function () {

    /**
     * Webhook route for the stateless queue.
     * 
     * @see \Karsjen\StatelessQueue\Http\Controllers\StatelessQueueController
     * @see \Karsjen\StatelessQueue\Http\Middleware\VerifyWebhookSignature
     */
    Route::post('/stateless/webhook', [StatelessQueueController::class, 'handle'])
        ->name('stateless.webhook')
        ->middleware('stateless.signature');
});