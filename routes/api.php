<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\AdvanceController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\UserController;

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

    // ✅ TAMBAH INI - PROFILE ROUTES
    // --------------------------------------------
    // USER PROFILE ROUTES (All authenticated users)
    // --------------------------------------------
    Route::prefix('user')->group(function () {
        Route::get('/profile', [UserController::class, 'getProfile']);
        Route::post('/change-password', [UserController::class, 'changePassword']);
        Route::put('/update-profile', [UserController::class, 'updateProfile']);
    });

    // --------------------------------------------
    // TRIP ROUTES
    // --------------------------------------------
    Route::prefix('trips')->group(function () {
        // ✅ Statistics MUST BE FIRST (before /{id})
        Route::get('statistics', [TripController::class, 'statistics']);
        
        // CRUD operations
        Route::get('/', [TripController::class, 'index']);
        Route::post('/', [TripController::class, 'store']);
        Route::get('/{id}', [TripController::class, 'show']);
        Route::put('/{id}', [TripController::class, 'update']);
        Route::delete('/{id}', [TripController::class, 'destroy']);

        // ✅ Get advances by trip (MUST BE BEFORE /{id}/...)
        Route::get('/{id}/advances', [AdvanceController::class, 'getByTrip']);

        // Trip actions
        Route::post('/{id}/submit', [TripController::class, 'submitForReview']);
        Route::post('/{id}/cancel', [TripController::class, 'cancel']);
        Route::post('/{id}/extension', [TripController::class, 'requestExtension']);
        Route::post('/{id}/cancel-extension', [TripController::class, 'cancelExtension']);

        // ✅ FIXED: Settlement routes - Ganti method name!
        Route::post('/{id}/approve-settlement', [TripController::class, 'approveByArea']);
        Route::post('/{id}/reject-settlement', [TripController::class, 'rejectSettlement']);
        Route::post('/{id}/approve-settlement-regional', [TripController::class, 'approveSettlementRegional']);
    });
    
    // --------------------------------------------
    // ADVANCE ROUTES
    // ✅ FIXED: Route order untuk avoid conflict!
    // --------------------------------------------
    Route::prefix('advances')->group(function () {
        // ✅ CRUD - List & Create dulu
        Route::get('/', [AdvanceController::class, 'index']);
        Route::post('/', [AdvanceController::class, 'store']);
        
        // ✅ ROUTE SPESIFIK HARUS DULUAN (SEBELUM /{id})!
        Route::get('/{id}/status-history', [AdvanceController::class, 'getStatusHistory']);
        
        // ✅ Route generic terakhir
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
        Route::post('/', [ReceiptController::class, 'store']); 
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

    // --------------------------------------------
    // USER MANAGEMENT ROUTES (HR)
    // --------------------------------------------
    Route::prefix('users')->group(function () {
        // ✅ Statistics HARUS DULUAN (sebelum /{id})
        Route::get('/statistics', [UserController::class, 'statistics']);
        
        // ✅ Real-time validation endpoints (HARUS SEBELUM /{id})
        Route::get('/check-nik', [UserController::class, 'checkNik']);
        Route::get('/check-email', [UserController::class, 'checkEmail']);
        
        // CRUD operations
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::get('/{id}', [UserController::class, 'show']);
        Route::put('/{id}', [UserController::class, 'update']);
        Route::delete('/{id}', [UserController::class, 'destroy']);
        
        // Reactivate user
        Route::post('/{id}/activate', [UserController::class, 'activate']);
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
            'POST /api/receipts' => 'Upload receipt',
            'GET /api/me' => 'Get current user',
            'GET /api/trips' => 'Get all trips',
            'GET /api/trips/statistics' => 'Get trip statistics',
            'GET /api/trips/{id}/advances' => 'Get advances for specific trip',
            'GET /api/advances/{id}/status-history' => 'Get advance status history',
            'POST /api/trips/{id}/cancel-extension' => 'Cancel trip extension',
            'POST /api/trips/{id}/approve-settlement' => 'Finance Area approve settlement',
            'POST /api/trips/{id}/reject-settlement' => 'Finance Area reject settlement',
            'POST /api/trips/{id}/approve-settlement-regional' => 'Finance Regional approve settlement',
            // ✅ TAMBAH PROFILE ENDPOINTS
            'GET /api/user/profile' => 'Get user profile',
            'POST /api/user/change-password' => 'Change password',
            'PUT /api/user/update-profile' => 'Update profile (phone, bank)',
        ]
    ], 404);
});