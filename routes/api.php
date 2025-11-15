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
use App\Http\Controllers\TripReviewController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ========================================
// PUBLIC ROUTES (No Authentication)
// ========================================

// Test routes
Route::get('/test', function () {
    return response()->json([
        'success' => true,
        'message' => 'API is working',
        'timestamp' => now()->toDateTimeString(),
        'version' => '1.0.0'
    ]);
});

// Test database connection
Route::get('/test-db', function () {
    try {
        DB::connection()->getPdo();
        $dbName = DB::connection()->getDatabaseName();
        return response()->json([
            'success' => true,
            'message' => 'Database connected successfully!',
            'database' => $dbName,
            'driver' => DB::connection()->getDriverName()
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => 'Database connection failed',
            'message' => $e->getMessage()
        ], 500);
    }
});

// Authentication routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// ========================================
// PROTECTED ROUTES (Require Authentication)
// ========================================

Route::middleware('auth:api')->group(function () {
    
    // --------------------------------------------
    // AUTH & USER ROUTES
    // --------------------------------------------
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // --------------------------------------------
    // TRIP ROUTES
    // --------------------------------------------
    // ✅ CRITICAL: statistics HARUS SEBELUM /{id}
    Route::get('/trips/statistics', [TripController::class, 'statistics']);
    
    Route::prefix('trips')->group(function () {
        // CRUD operations
        Route::get('statistics', [TripController::class, 'statistics']);
        Route::get('/', [TripController::class, 'index']);
        Route::post('/', [TripController::class, 'store']);
        Route::get('/{id}', [TripController::class, 'show']);
        Route::put('/{id}', [TripController::class, 'update']);
        

        // ✅ INI HARUS ADA - SEBELUM {id}
    Route::get('{id}/advances', [AdvanceController::class, 'getByTrip']);
        // Trip actions
        Route::post('/{id}/submit', [TripController::class, 'submitForReview']);
        Route::post('/{id}/cancel', [TripController::class, 'cancel']);
        Route::post('/{id}/extension', [TripController::class, 'requestExtension']);
        
        // ✅ NEW: Get advances by trip
        Route::get('/{id}/advances', [AdvanceController::class, 'getByTrip']);
    });
    
    // --------------------------------------------
    // ADVANCE ROUTES
    // --------------------------------------------
    Route::prefix('advances')->group(function () {
        // CRUD operations
        Route::get('/', [AdvanceController::class, 'index']);
        Route::post('/', [AdvanceController::class, 'store']);
        Route::get('/{id}', [AdvanceController::class, 'show']);
        Route::delete('/{id}', [AdvanceController::class, 'destroy']);

        // Advance actions (Finance)
        Route::post('/{id}/approve-area', [AdvanceController::class, 'approveByArea']);
        Route::post('/{id}/approve-regional', [AdvanceController::class, 'approveByRegional']);
        Route::post('/{id}/transfer', [AdvanceController::class, 'markAsTransferred']);
        Route::post('/{id}/reject', [AdvanceController::class, 'reject']);
    });
    
    // --------------------------------------------
    // RECEIPT ROUTES
    // --------------------------------------------
    Route::prefix('receipts')->group(function () {
        // CRUD operations
        Route::get('/', [ReceiptController::class, 'index']);
        Route::post('/', [ReceiptController::class, 'upload']);
        Route::get('/{id}', [ReceiptController::class, 'show']);
        Route::post('/{id}', [ReceiptController::class, 'update']);
        Route::delete('/{id}', [ReceiptController::class, 'delete']);
        
        // Receipt actions (Finance)
        Route::post('/{id}/verify', [ReceiptController::class, 'verify']);
        Route::post('/{id}/unverify', [ReceiptController::class, 'unverify']);
        Route::get('/{id}/download', [ReceiptController::class, 'download']);
    });
    
    // --------------------------------------------
    // NOTIFICATION ROUTES
    // --------------------------------------------
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'getUnreadCount']);
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/{id}', [NotificationController::class, 'delete']);
    });
    
});

// ========================================
// FALLBACK ROUTE
// ========================================
Route::fallback(function () {
    return response()->json([
        'success' => false,
        'message' => 'API endpoint not found',
        'available_endpoints' => [
            'GET /api/test' => 'Test API connection',
            'GET /api/test-db' => 'Test database connection',
            'POST /api/login' => 'Login user',
            'GET /api/me' => 'Get current user',
            'GET /api/trips' => 'Get all trips',
            'GET /api/trips/statistics' => 'Get trip statistics',
            'GET /api/trips/{id}/advances' => '✅ Get advances for specific trip',
        ]
    ], 404);
});