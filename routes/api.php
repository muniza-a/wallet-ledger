<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::post('/credit', [WalletController::class, 'credit']);
Route::post('/debit', [WalletController::class, 'debit']);
// '.*' lets any path segment reach the controller, which returns 400 for an invalid user_id.
Route::get('/balance/{userId}', [WalletController::class, 'balance'])->where('userId', '.*');
Route::get('/health', HealthController::class);
