<?php
namespace App\Http\Controllers;

use App\Models\Trip;
use App\Models\TripStatusHistory;
use App\Models\Advance;
use App\Models\AdvanceStatusHistory;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class TripController extends Controller
{
    /**
     * Get all trips (filtered by role)
     * ✅ UPDATED: Calculate total_advance & total_expenses for each trip
     */
    public function index(Request $request)
    {
        try {
            /** @var User $user */
            $user = Auth::user();
            
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

            // ✅ CALCULATE TOTALS FOR EACH TRIP
            $trips->transform(function ($trip) {
                $totalAdvance = Advance::where('trip_id', $trip->trip_id)
                    ->whereIn('status', ['approved_area', 'approved_regional', 'completed'])
                    ->sum('approved_amount');
                
                $totalExpenses = Receipt::where('trip_id', $trip->trip_id)
                    ->sum('amount');
                
                $trip->total_advance = $totalAdvance ?? 0;
                $trip->total_expenses = $totalExpenses ?? 0;
                
                return $trip;
            });

            return response()->json([
                'success' => true,
                'data' => $trips
            ]);
        } catch (\Exception $e) {
            Log::error('Trip index error: ' . $e->getMessage());
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
            Log::error('Statistics error: ' . $e->getMessage());
            
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
            'start_location_name' => 'nullable|string|max:255',
            'start_location_lat' => 'nullable|numeric',
            'start_location_lon' => 'nullable|numeric',
            'destination_lat' => 'nullable|numeric',
            'destination_lon' => 'nullable|numeric',
            'calculated_distance' => 'nullable|integer|min:0',
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
            'start_location_name' => $request->start_location_name,
            'start_location_lat' => $request->start_location_lat,
            'start_location_lon' => $request->start_location_lon,
            'destination_lat' => $request->destination_lat,
            'destination_lon' => $request->destination_lon,
            'calculated_distance' => $request->calculated_distance,
        ]);

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
     * ✅ UPDATED: Get trip detail with calculated totals
     */
    public function show($id)
    {
        try {
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

            // ✅ CALCULATE TOTAL ADVANCE (yang sudah approved)
            $totalAdvance = Advance::where('trip_id', $id)
                ->whereIn('status', ['approved_area', 'approved_regional', 'completed'])
                ->sum('approved_amount');
            
            // ✅ CALCULATE TOTAL EXPENSES (dari receipts)
            $totalExpenses = Receipt::where('trip_id', $id)
                ->sum('amount');
            
            // ✅ Tambahkan ke response
            $tripData = $trip->toArray();
            $tripData['total_advance'] = $totalAdvance ?? 0;
            $tripData['total_expenses'] = $totalExpenses ?? 0;

            return response()->json([
                'success' => true,
                'data' => $tripData
            ]);
        } catch (\Exception $e) {
            Log::error('Trip show error: ' . $e->getMessage());
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

        if ($trip->user_id !== $user->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

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

        TripStatusHistory::create([
            'trip_id' => $trip->trip_id,
            'old_status' => $trip->status,
            'new_status' => $trip->status,
            'changed_by' => $user->user_id,
            'notes' => '🟡 Trip extension requested: ' . $request->extension_reason,
            'changed_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Extension request submitted successfully',
            'data' => $trip->load('history.changer')
        ]);
    }

    /**
     * ✅ NEW: Cancel trip extension
     */
    public function cancelExtension($id)
    {
        try {
            $trip = Trip::findOrFail($id);
            
            /** @var User $user */
            $user = Auth::user();
            
            // Validasi: Hanya employee yang buat trip yang bisa cancel
            if ($trip->user_id !== $user->user_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }
            
            // Validasi: Trip harus masih active
            if ($trip->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot cancel extension. Trip is not active.'
                ], 400);
            }
            
            // Validasi: Harus ada extension
            if (!$trip->extended_end_date) {
                return response()->json([
                    'success' => false,
                    'message' => 'No extension to cancel.'
                ], 400);
            }
            
            // Reset extension fields
            $trip->update([
                'extended_end_date' => null,
                'extension_reason' => null,
                'extension_requested_at' => null,
            ]);
            
            // Log history
            TripStatusHistory::create([
                'trip_id' => $trip->trip_id,
                'old_status' => $trip->status,
                'new_status' => $trip->status,
                'changed_by' => $user->user_id,
                'notes' => 'Trip extension cancelled',
                'changed_at' => now()
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Extension cancelled successfully',
                'data' => $trip->fresh()->load('history.changer')
            ]);
            
        } catch (\Exception $e) {
            Log::error('Cancel extension error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel extension: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Submit trip for review (after trip ends)
     */
    public function submitForReview($id)
    {
        $trip = Trip::findOrFail($id);

        /** @var User $user */
        $user = Auth::user();

        if ($trip->user_id !== $user->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $oldStatus = $trip->status;
        $trip->update([
            'status' => 'awaiting_review',
            'submitted_at' => now()
        ]);

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
     * Approve settlement (Finance Area)
     */
    public function approveByArea(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $trip = Trip::findOrFail($id);
            
            if ($trip->status !== 'awaiting_review') {
                return response()->json([
                    'success' => false,
                    'message' => 'Trip is not awaiting review'
                ], 422);
            }
            
            $trip->status = 'under_review_regional';
            $trip->save();
            
            TripStatusHistory::create([
                'trip_id' => $trip->trip_id,
                'old_status' => 'awaiting_review',
                'new_status' => 'under_review_regional',
                'changed_by' => Auth::id(),
                'notes' => $request->notes ?? 'Approved by Finance Area',
                'changed_at' => now()
            ]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Settlement approved and forwarded to Finance Regional',
                'data' => $trip
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve settlement: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject settlement (Finance Area)
     */
    public function rejectSettlement(Request $request, $id)
    {
        $request->validate([
            'rejection_reason' => 'required|string'
        ]);
        
        DB::beginTransaction();
        try {
            $trip = Trip::findOrFail($id);
            
            $trip->status = 'active';
            $trip->rejection_reason = $request->rejection_reason;
            $trip->save();
            
            TripStatusHistory::create([
                'trip_id' => $trip->trip_id,
                'old_status' => 'awaiting_review',
                'new_status' => 'active',
                'changed_by' => Auth::id(),
                'notes' => $request->rejection_reason,
                'changed_at' => now()
            ]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Settlement rejected and returned to employee',
                'data' => $trip
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject settlement: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve settlement (Finance Regional)
     */
    public function approveSettlementRegional(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $trip = Trip::findOrFail($id);
            
            if ($trip->status !== 'under_review_regional') {
                return response()->json([
                    'success' => false,
                    'message' => 'Trip is not under regional review'
                ], 422);
            }
            
            $trip->status = 'completed';
            $trip->save();
            
            TripStatusHistory::create([
                'trip_id' => $trip->trip_id,
                'old_status' => 'under_review_regional',
                'new_status' => 'completed',
                'changed_by' => Auth::id(),
                'notes' => $request->notes ?? 'Approved by Finance Regional',
                'changed_at' => now()
            ]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Settlement completed',
                'data' => $trip
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
     * Cancel trip
     */
    public function cancel(Request $request, $id)
    {
        try {
            $trip = Trip::findOrFail($id);

            /** @var User $user */
            $user = Auth::user();

            if ($user->role === 'employee' && $trip->user_id !== $user->user_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            if (!in_array($trip->status, ['active', 'awaiting_review'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Can only cancel active or awaiting review trip'
                ], 422);
            }

            DB::beginTransaction();

            try {
                $pendingAdvances = Advance::where('trip_id', $trip->trip_id)
                                           ->where('status', 'pending')
                                           ->get();
                
                $deletedCount = 0;
                foreach ($pendingAdvances as $advance) {
                    AdvanceStatusHistory::where('advance_id', $advance->advance_id)->delete();
                    $advance->delete();
                    $deletedCount++;
                }

                $voidedCount = Advance::where('trip_id', $trip->trip_id)
                                      ->whereIn('status', ['approved_area', 'approved_regional'])
                                      ->update(['status' => 'voided']);

                $oldStatus = $trip->status;
                $trip->status = 'cancelled';
                $trip->save();

                TripStatusHistory::create([
                    'trip_id' => $trip->trip_id,
                    'old_status' => $oldStatus,
                    'new_status' => 'cancelled',
                    'changed_by' => $user->user_id,
                    'notes' => sprintf(
                        'Trip cancelled. Deleted %d pending advance(s), voided %d approved advance(s)',
                        $deletedCount,
                        $voidedCount
                    ),
                    'changed_at' => now()
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Trip cancelled successfully',
                    'data' => [
                        'trip' => $trip->load('history.changer'),
                        'deleted_advances' => $deletedCount,
                        'voided_advances' => $voidedCount
                    ]
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Cancel trip error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel trip: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete cancelled trip permanently
     */
    public function destroy($id)
    {
        try {
            $trip = Trip::findOrFail($id);

            /** @var User $user */
            $user = Auth::user();

            if ($user->role === 'employee' && $trip->user_id !== $user->user_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            if ($trip->status !== 'cancelled') {
                return response()->json([
                    'success' => false,
                    'message' => 'Can only delete cancelled trips'
                ], 422);
            }

            DB::beginTransaction();

            try {
                DB::table('advance_status_history')
                    ->whereIn('advance_id', function($query) use ($trip) {
                        $query->select('advance_id')
                              ->from('advances')
                              ->where('trip_id', $trip->trip_id);
                    })
                    ->delete();

                DB::table('notifications')
                    ->whereIn('advance_id', function($query) use ($trip) {
                        $query->select('advance_id')
                              ->from('advances')
                              ->where('trip_id', $trip->trip_id);
                    })
                    ->delete();

                Advance::where('trip_id', $trip->trip_id)->delete();

                DB::table('notifications')->where('trip_id', $trip->trip_id)->delete();

                TripStatusHistory::where('trip_id', $trip->trip_id)->delete();

                $receipts = DB::table('receipts')->where('trip_id', $trip->trip_id)->get();
                foreach ($receipts as $receipt) {
                    if ($receipt->file_path && Storage::disk('public')->exists($receipt->file_path)) {
                        Storage::disk('public')->delete($receipt->file_path);
                    }
                }
                DB::table('receipts')->where('trip_id', $trip->trip_id)->delete();

                $tripNumber = $trip->trip_number;
                $trip->delete();

                DB::commit();

                Log::info("Trip deleted: {$tripNumber} by user {$user->user_id}");

                return response()->json([
                    'success' => true,
                    'message' => 'Trip deleted permanently',
                    'data' => [
                        'trip_number' => $tripNumber
                    ]
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Delete trip error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete trip: ' . $e->getMessage()
            ], 500);
        }
    }
}