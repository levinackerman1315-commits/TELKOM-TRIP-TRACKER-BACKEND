<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\AdvanceController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\SettlementController;
use App\Http\Controllers\TripReviewController;
use App\Http\Controllers\NotificationController;

Route::get('/ping', function () {
    return response()->json(['message' => 'pong']);
});

Route::apiResource('users', UserController::class);
Route::apiResource('trips', TripController::class);
Route::apiResource('advances', AdvanceController::class);
Route::apiResource('receipts', ReceiptController::class);
Route::apiResource('settlements', SettlementController::class);
Route::apiResource('trip-reviews', TripReviewController::class);
Route::apiResource('notifications', NotificationController::class);