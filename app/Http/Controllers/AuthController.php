<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class AuthController extends Controller
{
    /**
     * Login user with EMAIL or NIK and return JWT token
     * ✅ UPDATED: Support login dengan email ATAU nik
     */
    public function login(Request $request)
    {
        // ✅ UPDATED: Validation untuk identifier
        $validator = Validator::make($request->all(), [
            'identifier' => 'required|string', // ✅ Changed to 'identifier'
            'password' => 'required|string|min:6',
        ], [
            'identifier.required' => 'Email or NIK is required',
            'password.required' => 'Password is required',
            'password.min' => 'Password must be at least 6 characters',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $identifier = $request->input('identifier'); // ✅ Changed to 'identifier'
        $password = $request->input('password');

        try {
            // ✅ STEP 1: Detect apakah identifier adalah email atau nik
            $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);
            
            // ✅ STEP 2: Find user berdasarkan email atau nik
            $user = null;
            if ($isEmail) {
                // Login dengan email
                $user = User::where('email', $identifier)->first();
                Log::info("Login attempt with EMAIL: {$identifier}");
            } else {
                // Login dengan NIK
                // ✅ Validation: NIK harus 6-8 karakter alfanumerik
                if (strlen($identifier) < 6 || strlen($identifier) > 8) {
                    return response()->json([
                        'error' => 'NIK must be 6-8 characters'
                    ], 422);
                }
                
                if (!preg_match('/^[a-zA-Z0-9]+$/', $identifier)) {
                    return response()->json([
                        'error' => 'NIK must contain only letters and numbers'
                    ], 422);
                }
                
                $user = User::where('nik', $identifier)->first();
                Log::info("Login attempt with NIK: {$identifier}");
            }

            // ✅ STEP 3: Check user exists
            if (!$user) {
                Log::warning("Login failed: User not found - {$identifier}");
                return response()->json([
                    'error' => 'Invalid credentials'
                ], 401);
            }

            // ✅ STEP 4: Check user is active
            if (!$user->is_active) {
                Log::warning("Login failed: User inactive - {$identifier}");
                return response()->json([
                    'error' => 'Account is deactivated. Please contact HR.'
                ], 403);
            }

            // ✅ STEP 5: Verify password
            if (!Hash::check($password, $user->password)) {
                Log::warning("Login failed: Invalid password - {$identifier}");
                return response()->json([
                    'error' => 'Invalid credentials'
                ], 401);
            }

            // ✅ STEP 6: Generate JWT token
            $token = JWTAuth::fromUser($user);
            
            if (!$token) {
                Log::error("Login failed: Could not create token - {$identifier}");
                return response()->json([
                    'error' => 'Could not create token'
                ], 500);
            }

            // ✅ STEP 7: Update last login
            $user->last_login = now();
            $user->save();

            Log::info("✅ Login successful: {$user->email} (Role: {$user->role})");

            // ✅ STEP 8: Return success response dengan data lengkap
            return response()->json([
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60,
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
                    'last_login' => $user->last_login,
                ]
            ]);

        } catch (JWTException $e) {
            Log::error("JWT Exception: " . $e->getMessage());
            return response()->json([
                'error' => 'Could not create token'
            ], 500);
        } catch (\Exception $e) {
            Log::error("Login Exception: " . $e->getMessage());
            return response()->json([
                'error' => 'An error occurred during login'
            ], 500);
        }
    }

    /**
     * Register new user
     * ✅ UPDATED: Add NIK validation (6-8 karakter alfanumerik)
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nik' => [
                'required',
                'string',
                'min:6',
                'max:8',
                'regex:/^[a-zA-Z0-9]+$/', // ✅ Only alphanumeric
                'unique:users,nik'
            ],
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            'role' => 'required|in:employee,finance_area,finance_regional,hr',
            'phone' => 'nullable|string|max:20',
            'department' => 'nullable|string|max:50',
            'position' => 'nullable|string|max:50',
            'office_location' => 'nullable|string|max:50',
            'area_code' => 'nullable|string|max:20',
        ], [
            'nik.required' => 'NIK is required',
            'nik.min' => 'NIK must be at least 6 characters',
            'nik.max' => 'NIK cannot exceed 8 characters',
            'nik.regex' => 'NIK must contain only letters and numbers',
            'nik.unique' => 'NIK already exists',
            'email.unique' => 'Email already exists',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'nik' => $request->nik,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'phone' => $request->phone,
            'department' => $request->department,
            'position' => $request->position,
            'office_location' => $request->office_location,
            'area_code' => $request->area_code,
            'is_active' => true,
        ]);

        Log::info("✅ User registered: {$user->email} (NIK: {$user->nik})");

        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user
        ], 201);
    }

    /**
     * Get authenticated user
     */
    public function me()
    {
        return response()->json(auth('api')->user());
    }

    /**
     * Logout user (invalidate token)
     */
    public function logout()
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            Log::info("✅ User logged out");
            return response()->json(['message' => 'Successfully logged out']);
        } catch (JWTException $e) {
            Log::error("Logout error: " . $e->getMessage());
            return response()->json(['error' => 'Failed to logout'], 500);
        }
    }

    /**
     * Refresh JWT token
     */
    public function refresh()
    {
        try {
            $newToken = JWTAuth::refresh(JWTAuth::getToken());
            return response()->json([
                'access_token' => $newToken,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60
            ]);
        } catch (JWTException $e) {
            Log::error("Token refresh error: " . $e->getMessage());
            return response()->json(['error' => 'Could not refresh token'], 500);
        }
    }
}