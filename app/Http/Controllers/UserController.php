<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    /**
     * ═══════════════════════════════════════════════════════════
     * PROFILE MANAGEMENT (ALL AUTHENTICATED USERS)
     * ═══════════════════════════════════════════════════════════
     */

    /**
     * Get authenticated user profile
     * GET /api/user/profile
     */
    public function getProfile()
    {
        try {
            // ✅ FIX: Get user dari User model, bukan dari auth guard
            $user = User::find(auth('api')->id());
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            return response()->json([
                'success' => true,
                'user' => [
                    'user_id' => $user->user_id,
                    'nik' => $user->nik,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'phone' => $user->phone,
                    'department' => $user->department,
                    'position' => $user->position,
                    'office_location' => $user->office_location,
                    'area_code' => $user->area_code,
                    'regional' => $user->regional,
                    'bank_account' => $user->bank_account,
                    'bank_name' => $user->bank_name,
                    'must_change_password' => $user->must_change_password,
                    'password_changed_at' => $user->password_changed_at,
                    'created_at' => $user->created_at,
                    'last_login' => $user->last_login,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("Get Profile Error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch profile'
            ], 500);
        }
    }

    /**
     * Change password (for all users)
     * POST /api/user/change-password
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'old_password' => 'required|string',
            'new_password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                Password::min(8)
                    ->mixedCase()
                    ->numbers()
            ],
        ], [
            'old_password.required' => 'Current password is required',
            'new_password.required' => 'New password is required',
            'new_password.min' => 'New password must be at least 8 characters',
            'new_password.confirmed' => 'Password confirmation does not match',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // ✅ FIX: Get user dari User model untuk akses save() method
            $user = User::find(auth('api')->id());
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // ✅ Verify old password
            if (!Hash::check($request->old_password, $user->password)) {
                Log::warning("Change password failed: Invalid old password for user {$user->email}");
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ], 422);
            }

            // ✅ Check if new password is same as old
            if (Hash::check($request->new_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'New password must be different from current password'
                ], 422);
            }

            // ✅ Update password - Sekarang save() akan work!
            $user->password = Hash::make($request->new_password);
            $user->password_changed_at = now();
            $user->must_change_password = false;
            $user->save();

            Log::info("✅ Password changed successfully for user: {$user->email}");

            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully'
            ]);

        } catch (\Exception $e) {
            Log::error("Change Password Error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to change password'
            ], 500);
        }
    }

    /**
     * Update profile (phone, bank info)
     * PUT /api/user/update-profile
     */
    public function updateProfile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'nullable|string|max:20|regex:/^[0-9+\-\s()]+$/',
            'bank_account' => 'nullable|string|max:30|regex:/^[0-9]+$/',
            'bank_name' => 'nullable|string|max:50',
        ], [
            'phone.regex' => 'Phone number format is invalid',
            'phone.max' => 'Phone number cannot exceed 20 characters',
            'bank_account.regex' => 'Bank account must contain only numbers',
            'bank_account.max' => 'Bank account cannot exceed 30 characters',
            'bank_name.max' => 'Bank name cannot exceed 50 characters',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // ✅ FIX: Get user dari User model untuk akses save() method
            $user = User::find(auth('api')->id());
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // ✅ Update fields
            if ($request->has('phone')) {
                $user->phone = $request->phone;
            }

            if ($request->has('bank_account')) {
                $user->bank_account = $request->bank_account;
            }

            if ($request->has('bank_name')) {
                $user->bank_name = $request->bank_name;
            }

            $user->save();

            Log::info("✅ Profile updated for user: {$user->email}");

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'user' => [
                    'user_id' => $user->user_id,
                    'phone' => $user->phone,
                    'bank_account' => $user->bank_account,
                    'bank_name' => $user->bank_name,
                    'name' => $user->name,
                    'email' => $user->email,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Update Profile Error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile'
            ], 500);
        }
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * USER MANAGEMENT (HR ONLY)
     * ═══════════════════════════════════════════════════════════
     */

    /**
     * Display a listing of all users (HR Dashboard)
     */
    public function index(Request $request)
    {
        try {
            $query = User::query();

            // ✅ Filter by role
            if ($request->has('role') && $request->role !== 'all') {
                $query->where('role', $request->role);
            }

            // ✅ Filter by is_active
            if ($request->has('is_active')) {
                $query->where('is_active', $request->is_active);
            }

            // ✅ Search by name or email
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                      ->orWhere('email', 'LIKE', "%{$search}%")
                      ->orWhere('nik', 'LIKE', "%{$search}%");
                });
            }

            // ✅ Sort
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // ✅ Get all users (tanpa pagination untuk dashboard)
            $users = $query->get();

            return response()->json([
                'success' => true,
                'users' => $users
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching users: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch users',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user statistics (HR Dashboard)
     */
    public function statistics()
    {
        try {
            $stats = [
                'total_users' => User::count(),
                'active_users' => User::where('is_active', 1)->count(),
                'inactive_users' => User::where('is_active', 0)->count(),
                'by_role' => [
                    'employee' => User::where('role', 'employee')->count(),
                    'finance_area' => User::where('role', 'finance_area')->count(),
                    'finance_regional' => User::where('role', 'finance_regional')->count(),
                    'hr' => User::where('role', 'hr')->count(),
                ],
                'recent_users' => User::orderBy('created_at', 'desc')->take(5)->get([
                    'user_id', 'nik', 'name', 'email', 'role', 'created_at'
                ])
            ];

            return response()->json([
                'success' => true,
                'statistics' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching user statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics'
            ], 500);
        }
    }

    /**
     * Display the specified user
     */
    public function show($id)
    {
        try {
            $user = User::findOrFail($id);

            // ✅ Remove password from response
            $user->makeHidden(['password']);

            return response()->json([
                'success' => true,
                'user' => $user
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }
    }

    /**
     * Store a newly created user (Create Account)
     */
    public function store(Request $request)
    {
        try {
            // ✅ Log request untuk debug
            Log::info('Creating new user', ['data' => $request->except(['password'])]);
            
            // ✅ Validation
            $validator = Validator::make($request->all(), [
                'nik' => 'required|string|max:20|unique:users,nik',
                'name' => 'required|string|max:100',
                'email' => 'required|email|max:100|unique:users,email',
                'password' => 'required|string|min:6',
                'phone' => 'nullable|string|max:20',
                'role' => 'required|in:employee,finance_area,finance_regional,hr',
                'department' => 'nullable|string|max:50',
                'position' => 'nullable|string|max:50',
                'office_location' => 'nullable|string|max:50',
                'area_code' => 'nullable|string|max:20',
                'regional' => 'nullable|string|max:50',
                'bank_account' => 'nullable|string|max:30',
                'bank_name' => 'nullable|string|max:50',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // ✅ Create user
            $user = User::create([
                'nik' => $request->nik,
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
                'role' => $request->role,
                'department' => $request->department,
                'position' => $request->position,
                'office_location' => $request->office_location,
                'area_code' => $request->area_code,
                'regional' => $request->regional,
                'bank_account' => $request->bank_account,
                'bank_name' => $request->bank_name,
                'is_active' => 1
            ]);

            // ✅ Remove password from response
            $user->makeHidden(['password']);

            Log::info('User created successfully', ['user_id' => $user->user_id]);

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'user' => $user
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating user: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified user
     */
    public function update(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            // ✅ Validation
            $validator = Validator::make($request->all(), [
                'nik' => 'sometimes|string|max:20|unique:users,nik,' . $id . ',user_id',
                'name' => 'sometimes|string|max:100',
                'email' => 'sometimes|email|max:100|unique:users,email,' . $id . ',user_id',
                'password' => 'sometimes|string|min:6',
                'phone' => 'nullable|string|max:20',
                'role' => 'sometimes|in:employee,finance_area,finance_regional,hr',
                'department' => 'nullable|string|max:50',
                'position' => 'nullable|string|max:50',
                'office_location' => 'nullable|string|max:50',
                'area_code' => 'nullable|string|max:20',
                'regional' => 'nullable|string|max:50',
                'bank_account' => 'nullable|string|max:30',
                'bank_name' => 'nullable|string|max:50',
                'is_active' => 'sometimes|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // ✅ Update user
            $updateData = $request->except(['password', 'user_id', 'created_at']);
            
            // ✅ Hash password if provided and not empty
            if ($request->has('password') && !empty($request->password)) {
                $updateData['password'] = Hash::make($request->password);
            } else {
                // ✅ Remove password from update data if empty
                unset($updateData['password']);
            }

            $user->update($updateData);

            // ✅ Remove password from response
            $user->makeHidden(['password']);

            Log::info('User updated successfully', ['user_id' => $user->user_id]);

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'user' => $user
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating user: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified user (Soft delete - set is_active = 0)
     */
    public function destroy($id)
    {
        try {
            $user = User::findOrFail($id);

            // ✅ Prevent deleting yourself
            $currentUserId = auth('api')->id();
            
            // ✅ Use loose comparison (==) to handle type differences
            if ($currentUserId == $id || $currentUserId == $user->user_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot delete your own account'
                ], 403);
            }

            // ✅ Soft delete - set is_active to 0
            $user->update(['is_active' => 0]);

            Log::info('User deactivated successfully', ['user_id' => $user->user_id]);

            return response()->json([
                'success' => true,
                'message' => 'User deactivated successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting user: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if NIK is available (for real-time validation)
     */
    public function checkNik(Request $request)
    {
        try {
            $nik = $request->query('nik');
            $userId = $request->query('user_id'); // For edit mode (exclude current user)

            if (!$nik) {
                return response()->json([
                    'success' => false,
                    'available' => false,
                    'message' => 'NIK is required'
                ], 400);
            }

            // Check if NIK exists
            $query = User::where('nik', $nik);
            
            // Exclude current user when editing
            if ($userId) {
                $query->where('user_id', '!=', $userId);
            }

            $exists = $query->exists();
            
            // ✅ Get existing user if found
            $existingUser = $exists ? $query->first() : null;

            return response()->json([
                'success' => true,
                'available' => !$exists,
                'nik' => $nik,
                'existing_user_id' => $existingUser ? $existingUser->user_id : null,
                'message' => $exists ? 'NIK already taken' : 'NIK is available'
            ]);

        } catch (\Exception $e) {
            Log::error('Error checking NIK: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'available' => false,
                'message' => 'Failed to check NIK'
            ], 500);
        }
    }

    /**
     * Check if Email is available (for real-time validation)
     */
    public function checkEmail(Request $request)
    {
        try {
            $email = $request->query('email');
            $userId = $request->query('user_id'); // For edit mode (exclude current user)

            if (!$email) {
                return response()->json([
                    'success' => false,
                    'available' => false,
                    'message' => 'Email is required'
                ], 400);
            }

            // Check if Email exists
            $query = User::where('email', $email);
            
            // Exclude current user when editing
            if ($userId) {
                $query->where('user_id', '!=', $userId);
            }

            $exists = $query->exists();
            
            // ✅ Get existing user if found
            $existingUser = $exists ? $query->first() : null;

            return response()->json([
                'success' => true,
                'available' => !$exists,
                'email' => $email,
                'existing_user_id' => $existingUser ? $existingUser->user_id : null,
                'message' => $exists ? 'Email already taken' : 'Email is available'
            ]);

        } catch (\Exception $e) {
            Log::error('Error checking email: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'available' => false,
                'message' => 'Failed to check email'
            ], 500);
        }
    }

    /**
     * Reactivate deactivated user OR Toggle user status
     */
    public function activate($id)
    {
        try {
            $user = User::findOrFail($id);
            
            // ✅ Toggle status: if active make inactive, if inactive make active
            $newStatus = !$user->is_active;
            $user->update(['is_active' => $newStatus]);

            $action = $newStatus ? 'activated' : 'deactivated';
            Log::info("User {$action} successfully", ['user_id' => $user->user_id]);

            return response()->json([
                'success' => true,
                'message' => "User {$action} successfully",
                'user' => $user->makeHidden(['password'])
            ]);

        } catch (\Exception $e) {
            Log::error('Error toggling user status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle user status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * BULK UPLOAD USERS FROM EXCEL
     * ═══════════════════════════════════════════════════════════
     */

    /**
     * Bulk create users from Excel upload
     * POST /api/users/bulk-create
     */
    public function bulkCreate(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'users' => 'required|array|min:1',
                'users.*.nik' => ['required', 'string', 'min:6', 'max:8', 'regex:/^[A-Za-z0-9]+$/'],
                'users.*.name' => 'required|string|max:100',
                'users.*.email' => ['required', 'email', 'regex:/^[a-zA-Z0-9._%+-]+@telkomakses\.co\.id$/'],
                'users.*.role' => 'required|in:employee,finance_area,finance_regional,hr',
                'users.*.phone' => ['nullable', 'string', 'regex:/^(08|628)[0-9]{8,11}$/'],
                'users.*.department' => 'nullable|string|max:50',
                'users.*.position' => 'nullable|string|max:50',
                'users.*.office_location' => 'nullable|string|max:100',
                'users.*.area' => 'nullable|string|max:50',
                'users.*.regional' => 'nullable|string|max:50',
                'users.*.area_code' => 'nullable|string|max:10',
                'users.*.bank_name' => 'nullable|string|max:50',
                'users.*.bank_account' => 'nullable|string|max:20',
            ], [
                'users.*.nik.min' => 'NIK must be at least 6 characters',
                'users.*.nik.max' => 'NIK cannot exceed 8 characters',
                'users.*.nik.regex' => 'NIK must contain only letters and numbers',
                'users.*.email.regex' => 'Email must use @telkomakses.co.id domain',
                'users.*.phone.regex' => 'Phone must start with 08 or 628',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $users = $request->users;
            $successCount = 0;
            $failedRows = [];
            $createdUsers = [];

            // Get existing NIKs and Emails from database
            $existingNiks = User::pluck('nik')->toArray();
            $existingEmails = User::pluck('email')->toArray();

            // Extract NIKs and Emails from uploaded data
            $uploadedNiks = array_column($users, 'nik');
            $uploadedEmails = array_column($users, 'email');

            foreach ($users as $index => $userData) {
                $rowNumber = $index + 2; // Excel row number (header is row 1)
                $errors = [];

                Log::info("Processing row {$rowNumber}", ['data' => $userData]);

                // ✅ Check NIK duplicate in database
                if (in_array($userData['nik'], $existingNiks)) {
                    $errors[] = "NIK '{$userData['nik']}' already exists in database";
                    Log::warning("Row {$rowNumber}: NIK duplicate in database", ['nik' => $userData['nik']]);
                }

                // ✅ Check NIK duplicate within Excel (excluding current row)
                $nikOccurrences = array_keys($uploadedNiks, $userData['nik']);
                if (count($nikOccurrences) > 1) {
                    $firstOccurrence = $nikOccurrences[0] + 2;
                    if ($firstOccurrence != $rowNumber) {
                        $errors[] = "NIK '{$userData['nik']}' already exists in row {$firstOccurrence}";
                        Log::warning("Row {$rowNumber}: NIK duplicate in Excel", ['nik' => $userData['nik']]);
                    }
                }

                // ✅ Check Email duplicate in database
                if (in_array($userData['email'], $existingEmails)) {
                    $errors[] = "Email '{$userData['email']}' already exists in database";
                    Log::warning("Row {$rowNumber}: Email duplicate in database", ['email' => $userData['email']]);
                }

                // ✅ Check Email duplicate within Excel (excluding current row)
                $emailOccurrences = array_keys($uploadedEmails, $userData['email']);
                if (count($emailOccurrences) > 1) {
                    $firstOccurrence = $emailOccurrences[0] + 2;
                    if ($firstOccurrence != $rowNumber) {
                        $errors[] = "Email '{$userData['email']}' already exists in row {$firstOccurrence}";
                        Log::warning("Row {$rowNumber}: Email duplicate in Excel", ['email' => $userData['email']]);
                    }
                }

                // ✅ If validation errors found, skip this row
                if (count($errors) > 0) {
                    $failedRows[] = [
                        'row' => $rowNumber,
                        'data' => $userData,
                        'errors' => $errors
                    ];
                    Log::warning("Row {$rowNumber} skipped due to validation errors", ['errors' => $errors]);
                    continue;
                }

                // ✅ Create user
                try {
                    Log::info("Attempting to create user for row {$rowNumber}", ['nik' => $userData['nik']]);
                    
                    $user = User::create([
                        'nik' => $userData['nik'],
                        'name' => $userData['name'],
                        'email' => $userData['email'],
                        'password' => Hash::make('TelkomAkses123'), // Default password
                        'role' => $userData['role'],
                        'phone' => isset($userData['phone']) && !empty($userData['phone']) ? $userData['phone'] : null,
                        'department' => isset($userData['department']) && !empty($userData['department']) ? $userData['department'] : null,
                        'position' => isset($userData['position']) && !empty($userData['position']) ? $userData['position'] : null,
                        'office_location' => isset($userData['office_location']) && !empty($userData['office_location']) ? $userData['office_location'] : null,
                        'regional' => isset($userData['regional']) && !empty($userData['regional']) ? $userData['regional'] : null,
                        'area_code' => isset($userData['area_code']) && !empty($userData['area_code']) ? $userData['area_code'] : null,
                        'bank_name' => isset($userData['bank_name']) && !empty($userData['bank_name']) ? $userData['bank_name'] : null,
                        'bank_account' => isset($userData['bank_account']) && !empty($userData['bank_account']) ? $userData['bank_account'] : null,
                        'is_active' => 1,
                        'must_change_password' => true,
                    ]);

                    $successCount++;
                    $createdUsers[] = $user->makeHidden(['password']);
                    
                    // ✅ Add to existing arrays to prevent duplicates in subsequent rows
                    $existingNiks[] = $userData['nik'];
                    $existingEmails[] = $userData['email'];

                    Log::info("✅ Row {$rowNumber}: User created successfully", [
                        'user_id' => $user->user_id,
                        'nik' => $userData['nik'],
                        'email' => $userData['email']
                    ]);

                } catch (\Exception $e) {
                    $errorMessage = $e->getMessage();
                    $failedRows[] = [
                        'row' => $rowNumber,
                        'data' => $userData,
                        'errors' => ['Failed to create user: ' . $errorMessage]
                    ];
                    Log::error("❌ Row {$rowNumber}: Bulk create failed", [
                        'error' => $errorMessage,
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            Log::info("Bulk upload completed", [
                'success_count' => $successCount,
                'failed_count' => count($failedRows)
            ]);

            return response()->json([
                'success' => true,
                'message' => "Bulk upload completed. {$successCount} user(s) created, " . count($failedRows) . " failed.",
                'data' => [
                    'success_count' => $successCount,
                    'failed_count' => count($failedRows),
                    'created_users' => $createdUsers,
                    'failed_rows' => $failedRows
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error in bulk create: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to process bulk upload: ' . $e->getMessage()
            ], 500);
        }
    }
}