<?php

namespace App\Http\Controllers;

use App\Models\Receipt;
use App\Models\Trip;
use App\Models\Advance;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;

class ReceiptController extends Controller
{
    /**
     * Get all receipts
     */
    public function index(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();
        
        $query = Receipt::with(['trip.user', 'advance', 'verifier']);

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

        // Filter by trip
        if ($request->has('trip_id')) {
            $query->where('trip_id', $request->trip_id);
        }

        // Filter by verification status
        if ($request->has('is_verified')) {
            $query->where('is_verified', $request->is_verified);
        }

        $receipts = $query->orderBy('uploaded_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $receipts
        ]);
    }

    /**
     * Upload receipt
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'trip_id' => 'required|exists:trips,trip_id',
            'advance_id' => 'nullable|exists:advances,advance_id',
            'receipt_date' => 'required|date',
            'amount' => 'required|numeric|min:0',
            'category' => 'required|in:fuel,meal,accommodation,transportation,parking,toll,other',
            'merchant_name' => 'nullable|string|max:100',
            'description' => 'required|string|max:255',
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120', // Max 5MB
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

        // Handle file upload
        $file = $request->file('file');
        $fileName = time() . '_' . $file->getClientOriginalName();
        $filePath = $file->storeAs('receipts', $fileName, 'public');

        // Generate receipt number
        $receiptNumber = 'RCP-' . date('Ymd') . '-' . str_pad(Receipt::count() + 1, 4, '0', STR_PAD_LEFT);

        $receipt = Receipt::create([
            'trip_id' => $request->trip_id,
            'advance_id' => $request->advance_id,
            'receipt_number' => $receiptNumber,
            'receipt_date' => $request->receipt_date,
            'amount' => $request->amount,
            'category' => $request->category,
            'merchant_name' => $request->merchant_name,
            'description' => $request->description,
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_size' => $file->getSize(),
        ]);

        // Update trip total_expenses
        $trip->total_expenses += $request->amount;
        $trip->save();

        return response()->json([
            'success' => true,
            'message' => 'Receipt uploaded successfully',
            'data' => $receipt
        ], 201);
    }

    /**
     * Get receipt detail
     */
    public function show($id)
    {
        $receipt = Receipt::with([
            'trip.user',
            'advance',
            'verifier'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $receipt
        ]);
    }

    /**
     * Update receipt
     */
    public function update(Request $request, $id)
    {
        $receipt = Receipt::findOrFail($id);

        // Check authorization
        /** @var User $currentUser */
        $currentUser = Auth::user();
        if ($receipt->trip->user_id !== $currentUser->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Cannot update verified receipt
        if ($receipt->is_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update verified receipt'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'receipt_date' => 'sometimes|date',
            'amount' => 'sometimes|numeric|min:0',
            'category' => 'sometimes|in:fuel,meal,accommodation,transportation,parking,toll,other',
            'merchant_name' => 'nullable|string|max:100',
            'description' => 'sometimes|string|max:255',
            'file' => 'sometimes|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $oldAmount = $receipt->amount;

        // Update basic fields
        $receipt->update($request->only([
            'receipt_date', 'amount', 'category', 'merchant_name', 'description'
        ]));

        // Handle file upload if new file provided
        if ($request->hasFile('file')) {
            // Delete old file
            Storage::disk('public')->delete($receipt->file_path);

            $file = $request->file('file');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $filePath = $file->storeAs('receipts', $fileName, 'public');

            $receipt->update([
                'file_path' => $filePath,
                'file_name' => $fileName,
                'file_size' => $file->getSize(),
            ]);
        }

        // Update trip total_expenses if amount changed
        if ($request->has('amount')) {
            $trip = $receipt->trip;
            $trip->total_expenses = $trip->total_expenses - $oldAmount + $receipt->amount;
            $trip->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Receipt updated successfully',
            'data' => $receipt
        ]);
    }

    /**
     * Delete receipt
     */
    public function destroy($id)
    {
        $receipt = Receipt::findOrFail($id);

        // Check authorization
        /** @var User $currentUser */
        $currentUser = Auth::user();
        if ($receipt->trip->user_id !== $currentUser->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Cannot delete verified receipt
        if ($receipt->is_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete verified receipt'
            ], 422);
        }

        // Update trip total_expenses
        $trip = $receipt->trip;
        $trip->total_expenses -= $receipt->amount;
        $trip->save();

        // Delete file
        Storage::disk('public')->delete($receipt->file_path);

        $receipt->delete();

        return response()->json([
            'success' => true,
            'message' => 'Receipt deleted successfully'
        ]);
    }

    /**
     * Verify receipt (Finance only)
     */
    public function verify(Request $request, $id)
    {
        /** @var User $user */
        $user = Auth::user();

        if (!in_array($user->role, ['finance_area', 'finance_regional'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only Finance can verify receipts'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'verification_notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $receipt = Receipt::findOrFail($id);

        if ($receipt->is_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Receipt already verified'
            ], 422);
        }

        $receipt->update([
            'is_verified' => true,
            'verified_by' => $user->user_id,
            'verification_notes' => $request->verification_notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Receipt verified successfully',
            'data' => $receipt
        ]);
    }
    /**
     * Unverify receipt (Finance only)
     */
    public function unverify(Request $request, $id)
    {
        /** @var User $user */
        $user = Auth::user();

        if (!in_array($user->role, ['finance_area', 'finance_regional'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only Finance can unverify receipts'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'verification_notes' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $receipt = Receipt::findOrFail($id);

        $receipt->update([
            'is_verified' => false,
            'verified_by' => null,
            'verified_at' => null,
            'verification_notes' => $request->verification_notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Receipt unverified',
            'data' => $receipt
        ]);
    }

    /**
     * Download receipt file
     */
    public function download($id)
    {
        $receipt = Receipt::findOrFail($id);

        $filePath = storage_path('app/public/' . $receipt->file_path);

        if (!file_exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'File not found'
            ], 404);
        }

        return response()->download($filePath, $receipt->file_name);
    }
}
