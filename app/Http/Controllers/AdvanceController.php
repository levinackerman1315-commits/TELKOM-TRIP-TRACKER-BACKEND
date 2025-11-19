<?php

namespace App\Http\Controllers;

use App\Models\Advance;
use App\Models\Trip;
use App\Models\AdvanceStatusHistory;
use App\Models\User;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdvanceController extends Controller
{
    /**
     * Get all advances (with optional filters)
     * ✅ UPDATED: Include employee_nik in response
     */
    public function index(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();
        
        $query = Advance::with(['trip.user', 'approverArea', 'approverRegional']);

        if ($request->has('trip_id')) {
            $query->where('trip_id', $request->trip_id);
        }

        // Filter by role
        if ($user->role === 'employee') {
            $query->whereHas('trip', function($q) use ($user) {
                $q->where('user_id', $user->user_id)
                  ->where('status', '!=', 'cancelled');
            });
        } elseif ($user->role === 'finance_area') {
            $query->whereHas('trip.user', function($q) use ($user) {
                $q->where('area_code', $user->area_code);
            })->whereHas('trip', function($q) {
                $q->where('status', '!=', 'cancelled');
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $advances = $query->orderBy('requested_at', 'desc')->get();

        // ✅ Transform data dengan employee_nik
        $transformedAdvances = $advances->map(function($advance) {
            $employee = $advance->trip && $advance->trip->user ? $advance->trip->user : null;
            
            return [
                'id' => $advance->advance_id,
                'advance_id' => $advance->advance_id,
                'advance_number' => $advance->advance_number,
                'trip_id' => $advance->trip_id,
                'request_type' => $advance->request_type,
                'requested_amount' => $advance->requested_amount,
                'approved_amount' => $advance->approved_amount,
                'status' => $advance->status,
                'request_reason' => $advance->request_reason,
                'rejection_reason' => $advance->rejection_reason,
                'notes' => $advance->notes,
                'supporting_document_path' => $advance->supporting_document_path,
                'supporting_document_name' => $advance->supporting_document_name,
                'requested_at' => $advance->requested_at,
                'approved_at_area' => $advance->approved_at_area,
                'approved_at_regional' => $advance->approved_at_regional,
                'transfer_date' => $advance->transfer_date,
                'transfer_reference' => $advance->transfer_reference,
                'created_at' => $advance->created_at,
                'updated_at' => $advance->updated_at,
                'trip_number' => $advance->trip ? $advance->trip->trip_number : null,
                'destination' => $advance->trip ? $advance->trip->destination : null,
                'employee_id' => $employee ? $employee->user_id : null,
                'employee_nik' => $employee ? $employee->nik : null, // ✅ ADDED
                'employee_name' => $employee ? $employee->name : null,
                'trip' => $advance->trip,
                'approver_area' => $advance->approverArea,
                'approver_regional' => $advance->approverRegional,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $transformedAdvances
        ]);
    }

    /**
     * Get status history for advance
     */
    public function getStatusHistory($id)
    {
        try {
            $advance = Advance::findOrFail($id);
            
            $history = AdvanceStatusHistory::where('advance_id', $id)
                ->with('changedBy:user_id,name')
                ->orderBy('changed_at', 'asc')
                ->get()
                ->map(function($item) {
                    return [
                        'id' => $item->id,
                        'advance_id' => $item->advance_id,
                        'old_status' => $item->old_status,
                        'new_status' => $item->new_status,
                        'changed_by' => $item->changed_by,
                        'changed_by_name' => $item->changedBy ? $item->changedBy->name : null,
                        'notes' => $item->notes,
                        'changed_at' => $item->changed_at
                    ];
                });
            
            return response()->json([
                'success' => true,
                'data' => $history
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get advances by specific trip
     */
    public function getByTrip(Request $request, $tripId)
    {
        /** @var User $user */
        $user = Auth::user();
        
        $trip = Trip::findOrFail($tripId);

        if ($user->role === 'employee' && $trip->user_id !== $user->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $advances = Advance::with(['trip.user', 'approverArea', 'approverRegional'])
                           ->where('trip_id', $tripId)
                           ->orderBy('requested_at', 'desc')
                           ->get();

        return response()->json([
            'success' => true,
            'data' => $advances
        ]);
    }

    /**
     * Store a new advance request
     * ✅ UPDATED: Auto-create status history
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'trip_id' => 'required|exists:trips,trip_id',
                'request_type' => 'required|in:initial,additional',
                'requested_amount' => 'required|numeric|min:1',
                'request_reason' => 'required|string',
                'supporting_document' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            /** @var User $user */
            $user = Auth::user();

            // Generate advance number
            $latestAdvance = Advance::whereYear('created_at', date('Y'))
                ->orderBy('advance_id', 'desc')
                ->first();
            
            $nextNumber = $latestAdvance 
                ? intval(substr($latestAdvance->advance_number, -4)) + 1 
                : 1;
            
            $advanceNumber = 'ADV-' . date('Ymd') . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

            // Handle file upload
            $documentPath = null;
            $documentName = null;
            
            if ($request->hasFile('supporting_document')) {
                $file = $request->file('supporting_document');
                $documentName = $file->getClientOriginalName();
                $documentPath = $file->store('advances', 'public');
            }

            // Create advance
            $advance = Advance::create([
                'trip_id' => $request->trip_id,
                'advance_number' => $advanceNumber,
                'request_type' => $request->request_type,
                'requested_amount' => $request->requested_amount,
                'request_reason' => $request->request_reason,
                'supporting_document_path' => $documentPath,
                'supporting_document_name' => $documentName,
                'status' => 'pending',
                'requested_by' => $user->user_id,
                'requested_at' => now()
            ]);

            // ✅ Auto-create status history
            AdvanceStatusHistory::create([
                'advance_id' => $advance->advance_id,
                'old_status' => null,
                'new_status' => 'pending',
                'changed_by' => $user->user_id,
                'notes' => 'Advance request created',
                'changed_at' => now()
            ]);

            // ✅ Load relationships
            $advance->load(['trip', 'employee']);

            return response()->json([
                'success' => true,
                'message' => 'Advance request created successfully',
                'data' => $advance
            ], 201);

        } catch (\Exception $e) {
            Log::error('Advance store error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create advance request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get advance detail
     * ✅ UPDATED: Include employee_nik in response
     */
    public function show($id)
    {
        try {
            // Eager load trip & employee
            $advance = Advance::with(['trip', 'employee'])
                ->findOrFail($id);
            
            // Get employee (bisa dari relation employee atau trip->user)
            $employee = $advance->employee ?? ($advance->trip ? $advance->trip->user : null);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $advance->advance_id,
                    'advance_id' => $advance->advance_id,
                    'advance_number' => $advance->advance_number,
                    'trip_id' => $advance->trip_id,
                    'trip_number' => $advance->trip->trip_number ?? null,
                    'destination' => $advance->trip->destination ?? null,
                    'employee_id' => $advance->requested_by,
                    'employee_nik' => $employee ? $employee->nik : null, // ✅ ADDED
                    'employee_name' => $employee ? $employee->name : null,
                    'request_type' => $advance->request_type,
                    'requested_amount' => $advance->requested_amount,
                    'approved_amount' => $advance->approved_amount,
                    'status' => $advance->status,
                    'request_reason' => $advance->request_reason,
                    'rejection_reason' => $advance->rejection_reason,
                    'notes' => $advance->notes,
                    'supporting_document_path' => $advance->supporting_document_path,
                    'supporting_document_name' => $advance->supporting_document_name,
                    'created_at' => $advance->created_at,
                    'updated_at' => $advance->updated_at,
                    'trip' => $advance->trip ? [
                        'trip_number' => $advance->trip->trip_number,
                        'destination' => $advance->trip->destination,
                        'purpose' => $advance->trip->purpose,
                        'start_date' => $advance->trip->start_date,
                        'end_date' => $advance->trip->end_date,
                        'status' => $advance->trip->status
                    ] : null
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve advance (Finance Area)
     * ✅ UPDATED: Auto-create status history + notification
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

        // ✅ Auto-create status history
        AdvanceStatusHistory::create([
            'advance_id' => $advance->advance_id,
            'old_status' => $oldStatus,
            'new_status' => 'approved_area',
            'changed_by' => $user->user_id,
            'notes' => 'Approved by Finance Area: Rp ' . number_format($request->approved_amount, 0, ',', '.'),
            'changed_at' => now()
        ]);

        // ✅ NOTIFICATION: Kirim ke Employee (FIX: Load trip dulu!)
        $advance->load('trip');
        if ($advance->trip) {
            Notification::create([
                'user_id' => $advance->trip->user_id,
                'type' => 'advance_approved', // ✅ FIX: Tambah type field!
                'title' => 'Advance Request Approved',
                'message' => "Your advance request {$advance->advance_number} (Rp " . number_format($advance->approved_amount, 0, ',', '.') . ") has been approved by Finance Area.",
                'link' => "/employee/trips/{$advance->trip_id}",
                'is_read' => false
            ]);
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Advance approved by Finance Area',
            'data' => $advance
        ]);
    }

    /**
     * Approve advance (Finance Regional)
     * ✅ UPDATED: Auto-create status history
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

        // ✅ Auto-create status history
        AdvanceStatusHistory::create([
            'advance_id' => $advance->advance_id,
            'old_status' => $oldStatus,
            'new_status' => 'approved_regional',
            'changed_by' => $user->user_id,
            'notes' => 'Approved by Finance Regional' . ($request->notes ? ': ' . $request->notes : ''),
            'changed_at' => now()
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Advance approved by Finance Regional',
            'data' => $advance
        ]);
    }

    /**
     * Mark advance as completed
     * ✅ UPDATED: Auto-create status history
     */
    public function markAsCompleted(Request $request, $id)
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
            'status' => 'completed',
            'transfer_date' => $request->transfer_date,
            'transfer_reference' => $request->transfer_reference,
        ]);

        // Update trip total_advance
        $trip = $advance->trip;
        $trip->total_advance += $advance->approved_amount;
        $trip->save();

        // ✅ Auto-create status history
        AdvanceStatusHistory::create([
            'advance_id' => $advance->advance_id,
            'old_status' => $oldStatus,
            'new_status' => 'completed',
            'changed_by' => $user->user_id,
            'notes' => 'Advance completed with reference: ' . $request->transfer_reference,
            'changed_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Advance marked as completed',
            'data' => $advance
        ]);
    }

    /**
     * Alias untuk backward compatibility
     */
    public function markAsTransferred(Request $request, $id)
    {
        return $this->markAsCompleted($request, $id);
    }

    /**
     * Reject advance
     * ✅ UPDATED: Auto-create status history
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

        // ✅ Auto-create status history
        AdvanceStatusHistory::create([
            'advance_id' => $advance->advance_id,
            'old_status' => $oldStatus,
            'new_status' => 'rejected',
            'changed_by' => $user->user_id,
            'notes' => 'Rejected: ' . $request->rejection_reason,
            'changed_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Advance rejected',
            'data' => $advance
        ]);
    }

    /**
     * Delete/void advance (for cancelled trips)
     */
    public function destroy($id)
    {
        /** @var User $user */
        $user = Auth::user();
        
        $advance = Advance::findOrFail($id);
        
        if ($user->role === 'employee') {
            if ($advance->trip->user_id !== $user->user_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }
            
            if ($advance->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Can only delete pending advances'
                ], 422);
            }
        }
        
        $advance->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Advance deleted successfully'
        ]);
    }
}