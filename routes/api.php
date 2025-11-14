<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\AdvanceController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SettlementController;

// Test route - untuk cek API jalan
Route::get('/test', function () {
    return response()->json([
        'message' => 'API is working',
        'timestamp' => now()->toDateTimeString(),
    ]);
});

// Test database connection
Route::get('/test-db', function () {
    try {
        DB::connection()->getPdo();
        return response()->json([
            'message' => 'Database connected successfully!',
            'database' => DB::connection()->getDatabaseName()
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Database connection failed',
            'message' => $e->getMessage()
        ], 500);
    }
});

// Public routes
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:api')->group(function () {
    Route::get('/me', [AuthController::class, 'me']); // ← HARUS ADA!
    Route::post('/logout', [AuthController::class, 'logout']);
    // Trip routes
    Route::apiResource('trips', TripController::class);
    Route::post('trips/{trip}/submit', [TripController::class, 'submit']);
    Route::post('trips/{trip}/cancel', [TripController::class, 'cancel']);
    Route::post('trips/{trip}/extend', [TripController::class, 'extend']);
    
    // Advance routes
    Route::apiResource('advances', AdvanceController::class);
    Route::post('advances/{advance}/approve', [AdvanceController::class, 'approve']);
    Route::post('advances/{advance}/reject', [AdvanceController::class, 'reject']);
    Route::post('advances/{advance}/transfer', [AdvanceController::class, 'transfer']);
    
    // Receipt routes
    Route::apiResource('receipts', ReceiptController::class);
    Route::post('receipts/{receipt}/verify', [ReceiptController::class, 'verify']);
    
    // Settlement routes
    Route::post('settlements/{trip}/submit', [SettlementController::class, 'submit']);
    Route::post('settlements/{trip}/review', [SettlementController::class, 'review']);
    Route::post('settlements/{trip}/approve-area', [SettlementController::class, 'approveArea']);
    Route::post('settlements/{trip}/approve-regional', [SettlementController::class, 'approveRegional']);
    Route::post('settlements/{trip}/complete', [SettlementController::class, 'complete']);
    
    // Notification routes
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
});