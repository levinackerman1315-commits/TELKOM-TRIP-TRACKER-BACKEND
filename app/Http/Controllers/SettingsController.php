<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class SettingsController extends Controller
{
    /**
     * Get price per km
     * GET /api/settings/price-per-km
     * Accessible by: ALL authenticated users (for NewTrip calculation)
     */
    public function getPricePerKm()
    {
        try {
            $pricePerKm = Setting::get('price_per_km', 5000);

            return response()->json([
                'success' => true,
                'data' => [
                    'price_per_km' => $pricePerKm
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get price per km: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get price per km'
            ], 500);
        }
    }

    /**
     * Update price per km
     * PUT /api/settings/price-per-km
     * Accessible by: Finance Area ONLY
     */
    public function updatePricePerKm(Request $request)
    {
        // Validate user role - Only Finance Area can update
        if ($request->user()->role !== 'finance_area') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only Finance Area can update price per km'
            ], 403);
        }

        // Validate input
        $validator = Validator::make($request->all(), [
            'price_per_km' => 'required|integer|min:1000|max:50000',
        ], [
            'price_per_km.required' => 'Price per km is required',
            'price_per_km.integer' => 'Price per km must be a number',
            'price_per_km.min' => 'Price per km must be at least Rp 1.000',
            'price_per_km.max' => 'Price per km cannot exceed Rp 50.000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $oldPrice = Setting::get('price_per_km', 5000);
            $newPrice = $request->input('price_per_km');
            
            // Update setting
            Setting::set('price_per_km', $newPrice);

            // Log the change
            Log::info('Price per km updated', [
                'user_id' => $request->user()->user_id,
                'user_name' => $request->user()->name,
                'old_price' => $oldPrice,
                'new_price' => $newPrice
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Price per km updated successfully',
                'data' => [
                    'price_per_km' => $newPrice,
                    'old_price' => $oldPrice
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update price per km: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update price per km'
            ], 500);
        }
    }

    /**
     * Get all settings
     * GET /api/settings
     * Accessible by: Finance Area & Finance Regional only
     */
    public function index(Request $request)
    {
        // Only Finance roles can view all settings
        if (!in_array($request->user()->role, ['finance_area', 'finance_regional'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        try {
            $settings = Setting::all();

            return response()->json([
                'success' => true,
                'data' => $settings
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get settings: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get settings'
            ], 500);
        }
    }
}