<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Models\TripStatusHistory;
use App\Models\Advance;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log; // ✅ TAMBAH INI
use Carbon\Carbon;

class TripController extends Controller
{
    /**
     * Get all trips (filtered by role)
     */
    public function index(Request $request)
    {
        try {
            /** @var User $user */
            $user = Auth::user();
            
            // ✅ HANYA LOAD RELASI YANG ADA (HAPUS reviews & settlement)
            $query = Trip::with([
                'user', 
                'advances', 
                'receipts', 
                'history.changer'
            ]);

            // Filter by role
            if ($user->role === 'employee') {
                $query->where('user_id', $user->user_id);
            } elseif ($user->role === 'finance_area') {
                $query->whereHas('user', function($q) use ($user) {
                    $q->where('area_code', $user->area_code);
                });
            }

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by date range
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->whereBetween('start_date', [$request->start_date, $request->end_date]);
            }

            $trips = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $trips
            ]);
        } catch (\Exception $e) {
            Log::error('Trip index error: ' . $e->getMessage()); // ✅ FIXED
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch trips',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get dashboard statistics
     */
    public function statistics()
    {
        try {
            /** @var User $user */
            $user = Auth::user();

            $query = Trip::where('user_id', $user->user_id);

            $stats = [
                'total_trips' => $query->count(),
                'active_trips' => (clone $query)->where('status', 'active')->count(),
                'completed_trips' => (clone $query)->where('status', 'completed')->count(),
                'pending_advances' => Advance::whereHas('trip', function($q) use ($user) {
                    $q->where('user_id', $user->user_id);
                })->where('status', 'pending')->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error('Statistics error: ' . $e->getMessage()); // ✅ FIXED
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create new trip
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'destination' => 'required|string|max:100',
            'purpose' => 'required|string',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'estimated_budget' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        /** @var User $user */
        $user = Auth::user();

        // Check if user has active trip
        $activeTrip = Trip::where('user_id', $user->user_id)
            ->where('status', 'active')
            ->first();

        if ($activeTrip) {
            return response()->json([
                'success' => false,
                'message' => 'You already have an active trip. Please complete it first.'
            ], 422);
        }

        // Generate trip number
        $tripNumber = 'TRP-' . date('Ymd') . '-' . str_pad(Trip::count() + 1, 4, '0', STR_PAD_LEFT);

        // Calculate duration
        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);
        $duration = $startDate->diffInDays($endDate) + 1;

        $trip = Trip::create([
            'user_id' => $user->user_id,
            'trip_number' => $tripNumber,
            'destination' => $request->destination,
            'purpose' => $request->purpose,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'duration' => $duration,
            'estimated_budget' => $request->estimated_budget,
            'status' => 'active',
        ]);

        // ✅ Log status history dengan changed_at
        TripStatusHistory::create([
            'trip_id' => $trip->trip_id,
            'old_status' => null,
            'new_status' => 'active',
            'changed_by' => $user->user_id,
            'notes' => 'Trip created',
            'changed_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Trip created successfully',
            'data' => $trip->load('history.changer')
        ], 201);
    }

    /**
     * Get trip detail
     */
    public function show($id)
    {
        try {
            // ✅ HAPUS relasi yang error (reviews, settlement)
            $trip = Trip::with([
                'user',
                'advances.approverArea',
                'advances.approverRegional',
                'receipts',
                'history.changer'
            ])->findOrFail($id);

            // Check authorization
            /** @var User $user */
            $user = Auth::user();
            if ($user->role === 'employee' && $trip->user_id !== $user->user_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $trip
            ]);
        } catch (\Exception $e) {
            Log::error('Trip show error: ' . $e->getMessage()); // ✅ FIXED
            return response()->json([
                'success' => false,
                'message' => 'Trip not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update trip
     */
    public function update(Request $request, $id)
    {
        $trip = Trip::findOrFail($id);

        /** @var User $user */
        $user = Auth::user();

        // Only owner can update and only if status is active
        if ($trip->user_id !== $user->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        if ($trip->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update trip that is not active'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'destination' => 'sometimes|string|max:100',
            'purpose' => 'sometimes|string',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'estimated_budget' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $trip->update($request->only([
            'destination', 'purpose', 'start_date', 'end_date', 'estimated_budget'
        ]));

        // Recalculate duration if dates changed
        if ($request->has('start_date') || $request->has('end_date')) {
            $startDate = Carbon::parse($trip->start_date);
            $endDate = Carbon::parse($trip->end_date);
            $trip->duration = $startDate->diffInDays($endDate) + 1;
            $trip->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Trip updated successfully',
            'data' => $trip->load('history.changer')
        ]);
    }

    /**
     * Request trip extension
     */
    public function requestExtension(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'extended_end_date' => 'required|date|after:end_date',
            'extension_reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $trip = Trip::findOrFail($id);

        /** @var User $user */
        $user = Auth::user();

        // Only owner can request extension
        if ($trip->user_id !== $user->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Can only extend active trip
        if ($trip->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Can only extend active trip'
            ], 422);
        }

        $trip->update([
            'extended_end_date' => $request->extended_end_date,
            'extension_reason' => $request->extension_reason,
            'extension_requested_at' => now(),
        ]);

        // ✅ Log status history dengan changed_at
        TripStatusHistory::create([
            'trip_id' => $trip->trip_id,
            'old_status' => $trip->status,
            'new_status' => $trip->status,
            'changed_by' => $user->user_id,
            'notes' => 'Trip extension requested: ' . $request->extension_reason,
            'changed_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Extension request submitted successfully',
            'data' => $trip->load('history.changer')
        ]);
    }

    /**
     * Submit trip for review (after trip ends)
     */
    public function submitForReview($id)
    {
        $trip = Trip::findOrFail($id);

        /** @var User $user */
        $user = Auth::user();

        // Only owner can submit
        if ($trip->user_id !== $user->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // ✅ HAPUS VALIDASI TANGGAL - Biar bisa submit kapan saja
        // Allow submission anytime for testing/demo purposes

        // Update status
        $oldStatus = $trip->status;
        $trip->update([
            'status' => 'awaiting_review',
            'submitted_at' => now()
        ]);

        // ✅ Log status history dengan changed_at
        TripStatusHistory::create([
            'trip_id' => $trip->trip_id,
            'old_status' => $oldStatus,
            'new_status' => 'awaiting_review',
            'changed_by' => $user->user_id,
            'notes' => 'Trip submitted for review',
            'changed_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Trip submitted for review',
            'data' => $trip->load('history.changer')
        ]);
    }

    /**
     * Cancel trip
     */
    public function cancel(Request $request, $id)
    {
        $trip = Trip::findOrFail($id);

        /** @var User $user */
        $user = Auth::user();

        // Only owner can cancel
        if ($trip->user_id !== $user->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Can only cancel active trip
        if ($trip->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Can only cancel active trip'
            ], 422);
        }

        $oldStatus = $trip->status;
        $trip->update(['status' => 'cancelled']);

        // ✅ Log status history dengan changed_at
        TripStatusHistory::create([
            'trip_id' => $trip->trip_id,
            'old_status' => $oldStatus,
            'new_status' => 'cancelled',
            'changed_by' => $user->user_id,
            'notes' => $request->input('reason', 'Trip cancelled by user'),
            'changed_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Trip cancelled successfully',
            'data' => $trip->load('history.changer')
        ]);
    }
}