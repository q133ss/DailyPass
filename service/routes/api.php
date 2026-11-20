<?php

use App\Http\Controllers\Api\ChargeHistoryController;
use App\Http\Controllers\Api\MoSmsWebhookController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SubscriptionWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/subscriptions', [SubscriptionController::class, 'store']);
Route::get('/status', [StatusController::class, 'show']);
Route::get('/charges', ChargeHistoryController::class);
Route::post('/webhooks/subscription', SubscriptionWebhookController::class);
Route::post('/webhooks/sms', MoSmsWebhookController::class);
