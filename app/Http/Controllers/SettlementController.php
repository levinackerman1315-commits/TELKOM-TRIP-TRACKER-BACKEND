<?php

namespace App\Http\Controllers;

use App\Models\Settlement;
use App\Models\Trip;
use App\Models\TripStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

/**
 * @property \App\Models\User $user
 */

class SettlementController extends Controller
{
    /**
     * Get all settlements
     */
    public function index(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        
        $query = Settlement::with(['trip.user', 'processor']);

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

        $settlements = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $settlements
        ]);
    }

    /**
     * Create settlement (Auto-generated after trip review completed)
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'trip_id' => 'required|exists:trips,trip_id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $trip = Trip::with(['advances', 'receipts'])->findOrFail($request->trip_id);

        // Check if settlement already exists
        if ($trip->settlement) {
            return response()->json([
                'success' => false,
                'message' => 'Settlement already exists for this trip'
            ], 422);
        }

        // Trip must be in review or completed status
        if (!in_array($trip->status, ['under_review_area', 'under_review_regional', 'completed'])) {
            return response()->json([
                'success' => false,
                'message' => 'Trip must be under review to create settlement'
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Calculate totals
            $totalAdvance = $trip->advances()
                ->where('status', 'transferred')
                ->sum('approved_amount');

            $totalReceipts = $trip->receipts()
                ->where('is_verified', true)
                ->sum('amount');

            $balance = $totalAdvance - $totalReceipts;

            // Determine settlement type
            if ($balance > 0) {
                $settlementType = 'refund'; // Employee returns money
                $settlementAmount = $balance;
            } elseif ($balance < 0) {
                $settlementType = 'payment'; // Company pays employee
                $settlementAmount = abs($balance);
            } else {
                $settlementType = 'balanced'; // No settlement needed
                $settlementAmount = 0;
            }
            // Generate settlement number
            $settlementNumber = 'STL-' . date('Ymd') . '-' . str_pad(Settlement::count() + 1, 4, '0', STR_PAD_LEFT);

            /** @var Settlement $settlement */
            $settlement = Settlement::create([
                'trip_id' => $trip->trip_id,
                'settlement_number' => $settlementNumber,
                'total_advance' => $totalAdvance,
                'total_receipts' => $totalReceipts,
                'balance' => $balance,
                'settlement_type' => $settlementType,
                'settlement_amount' => $settlementAmount,
                'status' => 'pending',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Settlement created successfully',
                'data' => $settlement
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create settlement: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
    /**
     * Get settlement by ID
     */
    public function show($id)
    {
        /** @var Settlement $settlement */
        $settlement = Settlement::with([
            'trip.user',
            'trip.advances',
            'trip.receipts.verifier',
            'processor'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $settlement
        ]);
    }

    /**
     * Process settlement (Finance only)
     */
    public function process(Request $request, $id)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (!in_array($user->role, ['finance_area', 'finance_regional'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only Finance can process settlement'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'settlement_date' => 'required|date',
            'transfer_reference' => 'required_if:settlement_type,refund,payment|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        /** @var Settlement $settlement */
        $settlement = Settlement::with('trip')->findOrFail($id);

        if ($settlement->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Settlement is not in pending status'
            ], 422);
        }

        DB::beginTransaction();
        try {
            $settlement->update([
                'status' => 'processed',
                'settlement_date' => $request->settlement_date,
                'transfer_reference' => $request->transfer_reference,
                'processed_by' => $user->user_id,
                'processed_at' => now(),
                'notes' => $request->notes,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Settlement processed successfully',
                'data' => $settlement
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to process settlement: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Complete settlement (Finance only)
     */
    public function complete(Request $request, $id)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (!in_array($user->role, ['finance_area', 'finance_regional'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only Finance can complete settlement'
            ], 403);
        }

        /** @var Settlement $settlement */
        $settlement = Settlement::with('trip')->findOrFail($id);

        if ($settlement->status !== 'processed') {
            return response()->json([
                'success' => false,
                'message' => 'Settlement must be processed first'
            ], 422);
        }

        DB::beginTransaction();
        try {
            $settlement->update([
                'status' => 'completed'
            ]);

            // Update trip status to completed
            $trip = $settlement->trip;
            $oldStatus = $trip->status;
            $trip->update([
                'status' => 'completed',
                'completed_at' => now()
            ]);

            // Log trip status history
            TripStatusHistory::create([
                'trip_id' => $trip->trip_id,
                'old_status' => $oldStatus,
                'new_status' => 'completed',
                'changed_by' => $user->user_id,
                'notes' => 'Settlement completed'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Settlement completed successfully',
                'data' => $settlement
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to complete settlement: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get settlement summary by trip
     */
    public function getByTrip($tripId)
    {
        $trip = Trip::with([
            'advances' => function($query) {
                $query->where('status', 'transferred');
            },
            'receipts' => function($query) {
                $query->where('is_verified', true);
            },
            'settlement'
        ])->findOrFail($tripId);

        $totalAdvance = $trip->advances->sum('approved_amount');
        $totalReceipts = $trip->receipts->sum('amount');
        $balance = $totalAdvance - $totalReceipts;

        return response()->json([
            'success' => true,
            'data' => [
                'trip' => $trip,
                'total_advance' => $totalAdvance,
                'total_receipts' => $totalReceipts,
                'balance' => $balance,
                'settlement_type' => $balance > 0 ? 'refund' : ($balance < 0 ? 'payment' : 'balanced'),
                'settlement' => $trip->settlement
            ]
        ]);
    }
}