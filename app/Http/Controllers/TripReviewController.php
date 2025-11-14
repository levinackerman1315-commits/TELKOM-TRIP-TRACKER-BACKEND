<?php

namespace App\Http\Controllers;

use App\Models\TripReview;
use App\Models\Trip;
use App\Models\TripStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class TripReviewController extends Controller
{
    /**
     * Get all reviews
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $user = Auth::user();
        
        $query = TripReview::with(['trip.user', 'reviewer']);

        // Filter by role
        if ($user->role === 'employee') {
            $query->whereHas('trip', function($q) use ($user) {
                $q->where('user_id', $user->id);
            });
        } elseif ($user->role === 'finance_area') {
            $query->where('review_level', 'area')
                ->whereHas('trip.user', function($q) use ($user) {
                    $q->where('area_code', $user->area_code);
                });
        }

        // Filter by trip
        if ($request->has('trip_id')) {
            $query->where('trip_id', $request->trip_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $reviews = $query->orderBy('reviewed_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $reviews
        ]);
    }

    /**
     * Create review for trip (Finance Area)
     */
    public function reviewByArea(Request $request, $tripId)
    {
        $user = Auth::user();

        if ($user->role !== 'finance_area') {
            return response()->json([
                'success' => false,
                'message' => 'Only Finance Area can review at this stage'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:checked,returned',
            'comments' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $trip = Trip::findOrFail($tripId);

        if ($trip->status !== 'awaiting_review') {
            return response()->json([
                'success' => false,
                'message' => 'Trip is not awaiting review'
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Create review record
            $review = TripReview::create([
                'trip_id' => $trip->id,
                'reviewer_id' => $user->id,
                'review_level' => 'area',
                'status' => $request->status,
                'comments' => $request->comments,
            ]);

            // Update trip status
            $oldStatus = $trip->status;
            if ($request->status === 'checked') {
                $trip->update(['status' => 'under_review_area']);
                
                TripStatusHistory::create([
                    'trip_id' => $trip->trip_id,
                    'old_status' => $oldStatus,
                    'new_status' => 'under_review_area',
                    'changed_by' => $user->user_id,
                    'notes' => 'Reviewed by Finance Area: ' . $request->comments
                ]);
            } else {
                $trip->update(['status' => 'awaiting_review']);
                
                TripStatusHistory::create([
                    'trip_id' => $trip->trip_id,
                    'old_status' => $oldStatus,
                    'new_status' => 'awaiting_review',
                    'changed_by' => $user->user_id,
                    'notes' => 'Returned by Finance Area: ' . $request->comments
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Review submitted successfully',
                'data' => $review
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit review: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Review by Finance Regional
     */
    public function reviewByRegional(Request $request, $tripId)
    {
        $user = Auth::user();

        if ($user->role !== 'finance_regional') {
            return response()->json([
                'success' => false,
                'message' => 'Only Finance Regional can review at this stage'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:completed,returned',
            'comments' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $trip = Trip::findOrFail($tripId);

        if ($trip->status !== 'under_review_area') {
            return response()->json([
                'success' => false,
                'message' => 'Trip must be reviewed by Finance Area first'
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Create review record
            $review = TripReview::create([
                'trip_id' => $trip->id,
                'reviewer_id' => $user->id,
                'review_level' => 'regional',
                'status' => $request->status,
                'comments' => $request->comments,
            ]);

            // Update trip status
            $oldStatus = $trip->status;
            if ($request->status === 'completed') {
                $trip->update(['status' => 'under_review_regional']);
                
                TripStatusHistory::create([
                    'trip_id' => $trip->trip_id,
                    'old_status' => $oldStatus,
                    'new_status' => 'under_review_regional',
                    'changed_by' => $user->user_id,
                    'notes' => 'Reviewed by Finance Regional: ' . $request->comments
                ]);
            } else {
                $trip->update(['status' => 'under_review_area']);
                
                TripStatusHistory::create([
                    'trip_id' => $trip->trip_id,
                    'old_status' => $oldStatus,
                    'new_status' => 'under_review_area',
                    'changed_by' => $user->user_id,
                    'notes' => 'Returned by Finance Regional: ' . $request->comments
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Review submitted successfully',
                'data' => $review
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit review: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get review detail
     */
    public function show($id)
    {
        $review = TripReview::with([
            'trip.user',
            'reviewer'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $review
        ]);
    }

    /**
     * Get reviews by trip
     */
    public function getByTrip($tripId)
    {
        $reviews = TripReview::with('reviewer')
            ->where('trip_id', $tripId)
            ->orderBy('reviewed_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $reviews
        ]);
    }
}