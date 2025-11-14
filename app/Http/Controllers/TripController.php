<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Models\TripStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class TripController extends Controller
{
    /**
     * Get all trips (filtered by role)
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        $query = Trip::with(['user', 'advances', 'receipts', 'reviews', 'settlement']);

        // Filter by role
        if ($user->role === 'employee') {
            $query->where('user_id', $user->user_id);
        } elseif ($user->role === 'finance_area') {
            // Finance area sees trips in their area
            $query->whereHas('user', function($q) use ($user) {
                $q->where('area_code', $user->area_code);
            });
        }
        // finance_regional sees all trips

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

        // Log status history
        TripStatusHistory::create([
            'trip_id' => $trip->trip_id,
            'old_status' => null,
            'new_status' => 'active',
            'changed_by' => $user->user_id,
            'notes' => 'Trip created'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Trip created successfully',
            'data' => $trip
        ], 201);
    }

    /**
     * Get trip detail
     */
    public function show($id)
    {
        $trip = Trip::with([
            'user',
            'advances.approverArea',
            'advances.approverRegional',
            'receipts.verifier',
            'reviews.reviewer',
            'settlement.processor',
            'statusHistory.changer'
        ])->findOrFail($id);

        // Check authorization
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
    }

    /**
     * Update trip
     */
    public function update(Request $request, $id)
    {
        $trip = Trip::findOrFail($id);

        // Only owner can update and only if status is active
        if ($trip->user_id !== Auth::user()->user_id) {
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
            'data' => $trip
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

        // Only owner can request extension
        if ($trip->user_id !== Auth::user()->user_id) {
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

        // Log status history
        TripStatusHistory::create([
            'trip_id' => $trip->trip_id,
            'old_status' => $trip->status,
            'new_status' => $trip->status,
            'changed_by' => Auth::user()->user_id,
            'notes' => 'Trip extension requested: ' . $request->extension_reason
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Extension request submitted successfully',
            'data' => $trip
        ]);
    }

    /**
     * Submit trip for review (after trip ends)
     */
    public function submitForReview($id)
    {
        $trip = Trip::findOrFail($id);

        // Only owner can submit
        if ($trip->user_id !== Auth::user()->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Can only submit if trip has ended
        $endDate = $trip->extended_end_date ?? $trip->end_date;
        if (Carbon::parse($endDate)->isFuture()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot submit trip that has not ended yet'
            ], 422);
        }

        // Update status
        $oldStatus = $trip->status;
        $trip->update([
            'status' => 'awaiting_review',
            'submitted_at' => now()
        ]);

        // Log status history
        TripStatusHistory::create([
            'trip_id' => $trip->trip_id,
            'old_status' => $oldStatus,
            'new_status' => 'awaiting_review',
            'changed_by' => Auth::user()->user_id,
            'notes' => 'Trip submitted for review'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Trip submitted for review',
            'data' => $trip
        ]);
    }

    /**
     * Cancel trip
     */
    public function cancel(Request $request, $id)
    {
        $trip = Trip::findOrFail($id);

        // Only owner can cancel
        if ($trip->user_id !== Auth::user()->user_id) {
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

        // Log status history
        TripStatusHistory::create([
            'trip_id' => $trip->trip_id,
            'old_status' => $oldStatus,
            'new_status' => 'cancelled',
            'changed_by' => Auth::user()->user_id,
            'notes' => $request->input('reason', 'Trip cancelled by user')
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Trip cancelled successfully',
            'data' => $trip
        ]);
    }
}