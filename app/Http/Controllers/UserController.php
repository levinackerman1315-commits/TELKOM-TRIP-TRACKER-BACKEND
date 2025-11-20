<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
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

            // ✅ Pagination
            $perPage = $request->get('per_page', 20);
            $users = $query->paginate($perPage);

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
                'regional' => 'nullable|string|max:50',  // ✅ TAMBAH regional
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
                'regional' => $request->regional,  // ✅ TAMBAH regional
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
                'regional' => 'nullable|string|max:50',  // ✅ TAMBAH regional
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
            $currentUserId = Auth::id();
            
            // ✅ FIX: Use loose comparison (==) instead of strict (===)
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
     * Reactivate deactivated user
     */
    public function activate($id)
    {
        try {
            $user = User::findOrFail($id);
            
            // ✅ Set is_active to 1
            $user->update(['is_active' => 1]);

            Log::info('User activated successfully', ['user_id' => $user->user_id]);

            return response()->json([
                'success' => true,
                'message' => 'User activated successfully',
                'user' => $user->makeHidden(['password'])
            ]);

        } catch (\Exception $e) {
            Log::error('Error activating user: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate user',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}