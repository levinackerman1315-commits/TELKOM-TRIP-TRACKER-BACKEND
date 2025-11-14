<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\AdvanceController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\SettlementController;
use App\Http\Controllers\TripReviewController;
use App\Http\Controllers\NotificationController;

// Test endpoint
Route::get('/ping', function () {
    return response()->json(['message' => 'pong']);
});

// Routes for authentication
Route::post('login', [AuthController::class, 'login']);
Route::post('register', [AuthController::class, 'register']);

// Protected routes (require authentication)
Route::middleware('auth:api')->group(function () {
    // Auth routes
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('refresh', [AuthController::class, 'refresh']);

    // Trips - resource routes
    Route::apiResource('trips', TripController::class);
    
    // Trips - custom routes
    Route::post('trips/{id}/request-extension', [TripController::class, 'requestExtension']);
    Route::post('trips/{id}/submit-review', [TripController::class, 'submitForReview']);
    Route::post('trips/{id}/cancel', [TripController::class, 'cancel']);

    // Advances - resource routes
    Route::apiResource('advances', AdvanceController::class);
    
    // Advances - custom routes
    Route::post('advances/{id}/approve-area', [AdvanceController::class, 'approveByArea']);
    Route::post('advances/{id}/approve-regional', [AdvanceController::class, 'approveByRegional']);
    Route::post('advances/{id}/mark-transferred', [AdvanceController::class, 'markAsTransferred']);
    Route::post('advances/{id}/reject', [AdvanceController::class, 'reject']);

    // Receipts - resource routes
    Route::apiResource('receipts', ReceiptController::class);
    
    // Receipts - custom routes
    Route::post('receipts/{id}/verify', [ReceiptController::class, 'verify']);
    Route::post('receipts/{id}/unverify', [ReceiptController::class, 'unverify']);
    Route::get('receipts/{id}/download', [ReceiptController::class, 'download']);

    // Settlements - resource routes
    Route::apiResource('settlements', SettlementController::class);
    
    // Settlements - custom routes
    Route::post('settlements/{id}/process', [SettlementController::class, 'process']);
    Route::post('settlements/{id}/complete', [SettlementController::class, 'complete']);
    Route::get('settlements/trip/{tripId}', [SettlementController::class, 'getByTrip']);

    // Trip Reviews - custom routes (tidak pakai apiResource)
    Route::get('trip-reviews', [TripReviewController::class, 'index']);
    Route::get('trip-reviews/{id}', [TripReviewController::class, 'show']);
    Route::post('trips/{tripId}/review-area', [TripReviewController::class, 'reviewByArea']);
    Route::post('trips/{tripId}/review-regional', [TripReviewController::class, 'reviewByRegional']);
    Route::get('trip-reviews/trip/{tripId}', [TripReviewController::class, 'getByTrip']);

    // Notifications - resource routes
    Route::apiResource('notifications', NotificationController::class)->only(['index', 'destroy']);
    
    // Notifications - custom routes
    Route::post('notifications/{id}/mark-read', [NotificationController::class, 'markAsRead']);
    Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
});