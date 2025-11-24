<?php

namespace App\Helpers;

use App\Models\Notification;
use Illuminate\Support\Facades\Log;

class NotificationHelper
{
    /**
     * Create notification for trip status change
     */
    public static function tripStatusChanged($trip, $newStatus, $notes = null)
    {
        try {
            $messages = [
                'awaiting_review' => [
                    'title' => 'Trip Submitted for Review',
                    'message' => "Your trip to {$trip->destination} has been submitted for review. Finance Area will check your receipts physically before approval.",
                    'type' => 'info',
                    'link' => "/employee/trips/{$trip->trip_id}"
                ],
                'under_review_regional' => [
                    'title' => 'Trip Approved by Finance Area',
                    'message' => "Your trip to {$trip->destination} has been approved by Finance Area and forwarded to Finance Regional for final approval.",
                    'type' => 'success',
                    'link' => "/employee/trips/{$trip->trip_id}"
                ],
                'completed' => [
                    'title' => 'Trip Completed Successfully',
                    'message' => "Your trip to {$trip->destination} has been completed and approved. You can now start a new trip.",
                    'type' => 'success',
                    'link' => "/employee/trips/{$trip->trip_id}"
                ],
                'active' => [
                    'title' => 'Settlement Rejected',
                    'message' => $notes 
                        ? "Your trip settlement was rejected. Reason: {$notes}. Please upload correct receipts and resubmit." 
                        : "Your trip settlement was rejected. Please upload correct receipts and resubmit.",
                    'type' => 'error',
                    'link' => "/employee/trips/{$trip->trip_id}"
                ],
                'cancelled' => [
                    'title' => 'Trip Cancelled',
                    'message' => $notes 
                        ? "Your trip to {$trip->destination} has been cancelled. {$notes}" 
                        : "Your trip to {$trip->destination} has been cancelled.",
                    'type' => 'warning',
                    'link' => "/employee/trips/{$trip->trip_id}"
                ],
            ];

            $data = $messages[$newStatus] ?? [
                'title' => 'Trip Status Updated',
                'message' => "Your trip status has been updated to: {$newStatus}",
                'type' => 'info',
                'link' => "/employee/trips/{$trip->trip_id}"
            ];

            Notification::create([
                'user_id' => $trip->user_id,
                'trip_id' => $trip->trip_id,
                'type' => $data['type'],
                'title' => $data['title'],
                'message' => $data['message'],
                'link' => $data['link'],
                'is_read' => false,
                'created_at' => now(),
            ]);

            Log::info("✅ Notification created for trip {$trip->trip_id}, status: {$newStatus}");
        } catch (\Exception $e) {
            Log::error("❌ Failed to create notification: " . $e->getMessage());
        }
    }

    /**
     * Create notification for trip extension
     */
   public static function tripExtensionRequested($trip)
{
    try {
        // ✅ 1. Notification untuk EMPLOYEE (confirmation)
        Notification::create([
            'user_id' => $trip->user_id,
            'trip_id' => $trip->trip_id,
            'type' => 'success',
            'title' => 'Trip Extended Successfully',
            'message' => "Your trip to {$trip->destination} has been extended until " . date('d M Y', strtotime($trip->extended_end_date)) . ". Reason: {$trip->extension_reason}",
            'link' => "/employee/trips/{$trip->trip_id}",
            'is_read' => false,
            'created_at' => now(),
        ]);

        // ✅ 2. Notification untuk FINANCE AREA (info only)
        // Get all Finance Area users in the same area as employee
        $financeAreaUsers = \App\Models\User::where('role', 'finance_area')
            ->where('area_code', $trip->user->area_code)
            ->get();
        
        foreach ($financeAreaUsers as $financeUser) {
            Notification::create([
                'user_id' => $financeUser->user_id,
                'trip_id' => $trip->trip_id,
                'type' => 'info',
                'title' => 'Trip Extended',
                'message' => "Employee {$trip->user->name} has extended trip to {$trip->destination} until " . date('d M Y', strtotime($trip->extended_end_date)) . ". Reason: {$trip->extension_reason}",
                'link' => "/finance-area/trips/{$trip->trip_id}",
                'is_read' => false,
                'created_at' => now(),
            ]);
        }

        // ✅ 3. Notification untuk FINANCE REGIONAL (info only - for audit)
        $financeRegionalUsers = \App\Models\User::where('role', 'finance_regional')->get();
        
        foreach ($financeRegionalUsers as $regionalUser) {
            Notification::create([
                'user_id' => $regionalUser->user_id,
                'trip_id' => $trip->trip_id,
                'type' => 'info',
                'title' => 'Trip Extended (Audit)',
                'message' => "Employee {$trip->user->name} ({$trip->user->area_code}) extended trip to {$trip->destination} until " . date('d M Y', strtotime($trip->extended_end_date)) . ". This may affect settlement review.",
                'link' => "/finance-regional/settlements/{$trip->trip_id}",
                'is_read' => false,
                'created_at' => now(),
            ]);
        }

        Log::info("✅ Extension notifications created for trip {$trip->trip_id} (Employee + Finance Area + Finance Regional)");
    } catch (\Exception $e) {
        Log::error("❌ Failed to create extension notification: " . $e->getMessage());
    }
}
    /**
     * Create notification for trip extension cancelled
     */
    public static function tripExtensionCancelled($trip)
    {
        try {
            Notification::create([
                'user_id' => $trip->user_id,
                'trip_id' => $trip->trip_id,
                'type' => 'info',
                'title' => 'Trip Extension Cancelled',
                'message' => "Your trip extension for {$trip->destination} has been cancelled. Original end date applies.",
                'link' => "/employee/trips/{$trip->trip_id}",
                'is_read' => false,
                'created_at' => now(),
            ]);

            Log::info("✅ Extension cancelled notification created for trip {$trip->trip_id}");
        } catch (\Exception $e) {
            Log::error("❌ Failed to create extension cancelled notification: " . $e->getMessage());
        }
    }

    /**
     * Create notification for advance status change
     */
    public static function advanceStatusChanged($advance, $newStatus, $notes = null)
    {
        try {
            if (!$advance->relationLoaded('trip')) {
                $advance->load('trip');
            }

            $messages = [
                'approved_area' => [
                    'title' => 'Advance Approved by Finance Area',
                    'message' => "Your advance request of Rp " . number_format($advance->approved_amount, 0, ',', '.') . " has been approved by Finance Area. Forwarded to Finance Regional.",
                    'type' => 'success',
                    'link' => "/employee/trips/{$advance->trip_id}"
                ],
                'approved_regional' => [
                    'title' => 'Advance Approved by Finance Regional',
                    'message' => "Your advance request has been approved by Finance Regional. Finance will transfer the funds to your account soon.",
                    'type' => 'success',
                    'link' => "/employee/trips/{$advance->trip_id}"
                ],
                'completed' => [
                    'title' => 'Advance Transferred',
                    'message' => "Advance of Rp " . number_format($advance->approved_amount, 0, ',', '.') . " has been transferred to your account.",
                    'type' => 'success',
                    'link' => "/employee/trips/{$advance->trip_id}"
                ],
                'rejected' => [
                    'title' => 'Advance Rejected',
                    'message' => $notes 
                        ? "Your advance request was rejected. Reason: {$notes}" 
                        : "Your advance request was rejected. Please contact Finance for details.",
                    'type' => 'error',
                    'link' => "/employee/trips/{$advance->trip_id}"
                ],
            ];

            $data = $messages[$newStatus] ?? [
                'title' => 'Advance Status Updated',
                'message' => "Your advance status has been updated to: {$newStatus}",
                'type' => 'info',
                'link' => "/employee/trips/{$advance->trip_id}"
            ];

            Notification::create([
                'user_id' => $advance->trip->user_id,
                'trip_id' => $advance->trip_id,
                'advance_id' => $advance->advance_id,
                'type' => $data['type'],
                'title' => $data['title'],
                'message' => $data['message'],
                'link' => $data['link'],
                'is_read' => false,
                'created_at' => now(),
            ]);

            Log::info("✅ Notification created for advance {$advance->advance_id}, status: {$newStatus}");
        } catch (\Exception $e) {
            Log::error("❌ Failed to create advance notification: " . $e->getMessage());
        }
    }

    /**
     * Create notification for receipt verification
     */
    public static function receiptVerified($receipt)
    {
        try {
            if (!$receipt->relationLoaded('trip')) {
                $receipt->load('trip');
            }

            Notification::create([
                'user_id' => $receipt->trip->user_id,
                'trip_id' => $receipt->trip_id,
                'type' => 'success',
                'title' => 'Receipt Verified',
                'message' => "Your receipt {$receipt->receipt_number} for Rp " . number_format($receipt->amount, 0, ',', '.') . " has been verified by Finance.",
                'link' => "/employee/trips/{$receipt->trip_id}",
                'is_read' => false,
                'created_at' => now(),
            ]);

            Log::info("✅ Receipt verified notification created for receipt {$receipt->receipt_id}");
        } catch (\Exception $e) {
            Log::error("❌ Failed to create receipt notification: " . $e->getMessage());
        }
    }
}