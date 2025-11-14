<?php

namespace App\Http\Controllers;

use App\Models\Advance;
use App\Models\Trip;
use App\Models\AdvanceStatusHistory;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class AdvanceController extends Controller
{
    /**
     * Get all advances
     */
    public function index(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();
        
        $query = Advance::with(['trip.user', 'approverArea', 'approverRegional']);

        // Filter by role
        if ($user->role === 'employee') {
            $query->whereHas('trip', function($q) use ($user) {
                $q->where('user_id', $user->user_id);
            });
        } elseif ($user->role === 'finance_area') {
            $query->whereHas('trip.user', function($q) use ($user) {
                $q->where('area_code', $user->area_code);
            });
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $advances = $query->orderBy('requested_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $advances
        ]);
    }

    /**
     * Create advance request
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'trip_id' => 'required|exists:trips,trip_id',
            'request_type' => 'required|in:initial,additional',
            'requested_amount' => 'required|numeric|min:0',
            'request_reason' => 'required_if:request_type,additional|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $trip = Trip::findOrFail($request->trip_id);

        // Check authorization
        /** @var User $currentUser */
        $currentUser = Auth::user();
        if ($trip->user_id !== $currentUser->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Check if trip is active
        if ($trip->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Can only request advance for active trip'
            ], 422);
        }

        // Check if initial advance already exists
        if ($request->request_type === 'initial') {
            $existingInitial = Advance::where('trip_id', $trip->trip_id)
                ->where('request_type', 'initial')
                ->first();

            if ($existingInitial) {
                return response()->json([
                    'success' => false,
                    'message' => 'Initial advance already requested for this trip'
                ], 422);
            }
        }

        // Generate advance number
        $advanceNumber = 'ADV-' . date('Ymd') . '-' . str_pad(Advance::count() + 1, 4, '0', STR_PAD_LEFT);

        $advance = Advance::create([
            'trip_id' => $request->trip_id,
            'advance_number' => $advanceNumber,
            'request_type' => $request->request_type,
            'requested_amount' => $request->requested_amount,
            'request_reason' => $request->request_reason,
            'status' => 'pending',
            'requested_at' => now()
        ]);

        // Log status history
        /** @var User $currentUser */
        $currentUser = Auth::user();
        AdvanceStatusHistory::create([
            'advance_id' => $advance->advance_id,
            'old_status' => null,
            'new_status' => 'pending',
            'changed_by' => $currentUser->user_id,
            'notes' => 'Advance request created'
        ]);

        // Create notification for finance area
        // TODO: Implement notification

        return response()->json([
            'success' => true,
            'message' => 'Advance request created successfully',
            'data' => $advance
        ], 201);
    }

    /**
     * Get advance detail
     */
    public function show($id)
    {
        $advance = Advance::with([
            'trip.user',
            'approverArea',
            'approverRegional',
            'statusHistory.changer'
        ])->findOrFail($id);
        return response()->json([
            'success' => true,
            'data' => $advance
        ]);
    }

    /**
     * Approve advance (Finance Area)
     */
    public function approveByArea(Request $request, $id)
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->role !== 'finance_area') {
            return response()->json([
                'success' => false,
                'message' => 'Only Finance Area can approve at this stage'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'approved_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $advance = Advance::findOrFail($id);

        // Check if status is pending
        if ($advance->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Advance is not in pending status'
            ], 422);
        }

        $oldStatus = $advance->status;
        $advance->update([
            'approved_amount' => $request->approved_amount,
            'status' => 'approved_area',
            'approved_by_area' => $user->user_id,
            'approved_at_area' => now(),
            'notes' => $request->notes,
        ]);

        // Log status history
        AdvanceStatusHistory::create([
            'advance_id' => $advance->advance_id,
            'old_status' => $oldStatus,
            'new_status' => 'approved_area',
            'changed_by' => $user->user_id,
            'notes' => 'Approved by Finance Area: ' . $request->notes
        ]);
        return response()->json([
            'success' => true,
            'message' => 'Advance approved by Finance Area',
            'data' => $advance
        ]);
    }

    /**
     * Approve advance (Finance Regional)
     */
    public function approveByRegional(Request $request, $id)
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->role !== 'finance_regional') {
            return response()->json([
                'success' => false,
                'message' => 'Only Finance Regional can approve at this stage'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $advance = Advance::findOrFail($id);

        // Check if status is approved_area
        if ($advance->status !== 'approved_area') {
            return response()->json([
                'success' => false,
                'message' => 'Advance must be approved by Finance Area first'
            ], 422);
        }

        $oldStatus = $advance->status;
        $advance->update([
            'status' => 'approved_regional',
            'approved_by_regional' => $user->user_id,
            'approved_at_regional' => now(),
            'notes' => $request->notes,
        ]);

        // Log status history
        AdvanceStatusHistory::create([
            'advance_id' => $advance->advance_id,
            'old_status' => $oldStatus,
            'new_status' => 'approved_regional',
            'changed_by' => $user->user_id,
            'notes' => 'Approved by Finance Regional: ' . $request->notes
        ]);
        return response()->json([
            'success' => true,
            'message' => 'Advance approved by Finance Regional',
            'data' => $advance
        ]);
    }

    /**
     * Mark advance as transferred
     */
    public function markAsTransferred(Request $request, $id)
    {
        /** @var User $user */
        $user = Auth::user();

        if (!in_array($user->role, ['finance_area', 'finance_regional'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'transfer_date' => 'required|date',
            'transfer_reference' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $advance = Advance::findOrFail($id);

        if ($advance->status !== 'approved_regional') {
            return response()->json([
                'success' => false,
                'message' => 'Advance must be approved by Finance Regional first'
            ], 422);
        }

        $oldStatus = $advance->status;
        $advance->update([
            'status' => 'transferred',
            'transfer_date' => $request->transfer_date,
            'transfer_reference' => $request->transfer_reference,
        ]);

        // Update trip total_advance
        $trip = $advance->trip;
        $trip->total_advance += $advance->approved_amount;
        $trip->save();

        // Log status history
        AdvanceStatusHistory::create([
            'advance_id' => $advance->advance_id,
            'old_status' => $oldStatus,
            'new_status' => 'transferred',
            'changed_by' => $user->user_id,
            'notes' => 'Advance transferred with reference: ' . $request->transfer_reference
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Advance marked as transferred',
            'data' => $advance
        ]);
    }
    /**
     * Reject advance
     */
    public function reject(Request $request, $id)
    {
        /** @var User $user */
        $user = Auth::user();

        if (!in_array($user->role, ['finance_area', 'finance_regional'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $advance = Advance::findOrFail($id);

        $oldStatus = $advance->status;
        $advance->update([
            'status' => 'rejected',
            'rejection_reason' => $request->rejection_reason,
        ]);

        // Log status history
        AdvanceStatusHistory::create([
            'advance_id' => $advance->advance_id,
            'old_status' => $oldStatus,
            'new_status' => 'rejected',
            'changed_by' => $user->user_id,
            'notes' => 'Rejected: ' . $request->rejection_reason
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Advance rejected',
            'data' => $advance
        ]);
    }
}