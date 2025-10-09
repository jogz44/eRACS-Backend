<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use App\Models\BarangayUser;
use App\Models\Barangay;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\AdminAuthController;

class AuthController extends Controller
{

    protected $otp;

    public function __construct(OtpService $otp)
    {
        $this->otp = $otp;
    }
     public function index()

    {
        $barangayUser = BarangayUser::all();
        return response()->json($barangayUser);

    }

public function register(Request $request)
{
    $validated = $request->validate([
        'first_name' => 'required|string|max:255',
        'middle_name' => 'nullable|string|max:255',
        'last_name' => 'required|string|max:255',
        'barangay_id' => 'required|exists:barangays,id',
        'position_id' => 'required|exists:barangay_positions,id',
        'suffix' => 'nullable|string|max:255',
        'email' => 'required|string|email|max:255|unique:barangay_users',
        'username' => 'required|string|max:255|unique:barangay_users',
        'password' => 'required|string|min:8|confirmed',
        'photo_path' => 'required|string',
        'otp' => 'required|string'
    ]);

    // if (! $this->otp->verify($validated['email'], $validated['otp'])) {
    //     return response()->json([
    //         'status'  => 'error',
    //         'message' => 'Incorrect OTP',
    //     ], 400);
    // }

    $user = BarangayUser::create([
        'first_name' => $validated['first_name'],
        'middle_name' => $validated['middle_name'],
        'last_name' => $validated['last_name'],
        'barangay_id' => $validated['barangay_id'],
        'position_id' => $validated['position_id'],
        'suffix' => $validated['suffix'],
        'email' => $validated['email'],
        'username' => $validated['username'],
        'password' => Hash::make($validated['password']),
        'photo_path' => $validated['photo_path'],
        'is_approved' => false,
        'role' => 'barangay_user'
    ]);

    //AdminAuthController::logUserAction($user, 'Registration','User registration to system');

    return response()->json([
        'message' => 'Registration successful',
        'user' => $user
    ], 201);
}

   public function uploadPhoto(Request $request)
   {
    $validated = $request->validate([
        'photo' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048'
    ]);

    $path = $request->file('photo')->store('profile-photos', 'public');

    return response()->json([
        'path' => $path,
        'url' => Storage::url($path)
    ]);
}

/**
 * Get all approved users from the same barangay as the authenticated user
 */
public function getBarangayUsers(Request $request)
{
    try {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 401);
        }

        $users = BarangayUser::where('barangay_id', $user->barangay_id)
            ->where('is_approved', true)
            ->with(['barangay', 'position'])
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->full_name,
                    'username' => $user->username,
                    'position' => $user->position ? $user->position->name : 'N/A',
                    'barangay_name' => $user->barangay ? $user->barangay->name : 'N/A',
                    'permissions' => $user->permissions ?? [
                        'view' => true,
                        'add' => true,
                        'edit' => true,
                        'delete' => false,
                        'print' => true,
                    ]
                ];
            });

        return response()->json($users);
    } catch (\Exception $e) {
        return response()->json([
            'message' => 'Failed to fetch barangay users',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * Update user permissions within the same barangay
 */
public function updateUserPermissions(Request $request, $userId)
{
    try {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 401);
        }

        // Get the target user
        $targetUser = BarangayUser::where('id', $userId)
            ->where('barangay_id', $user->barangay_id)
            ->first();

        if (!$targetUser) {
            return response()->json([
                'message' => 'User not found or not in your barangay'
            ], 404);
        }

        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.view' => 'boolean',
            'permissions.add' => 'boolean',
            'permissions.edit' => 'boolean',
            'permissions.delete' => 'boolean',
            'permissions.print' => 'boolean',
        ]);

        // Log the permission change
        $oldPermissions = $targetUser->permissions ?? [];
        $newPermissions = $validated['permissions'];

        // If user has no permissions set, assume they have default permissions (all enabled except delete)
        if (empty($oldPermissions)) {
            $oldPermissions = [
                'view' => true,
                'add' => true,
                'edit' => true,
                'delete' => false,
                'print' => true
            ];
        }

        // Create a detailed log of what changed
        $changes = [];
        $permissionNames = ['view' => 'View', 'add' => 'Add', 'edit' => 'Edit', 'delete' => 'Delete', 'print' => 'Print'];

        foreach ($permissionNames as $key => $label) {
            $oldValue = $oldPermissions[$key] ?? false;
            $newValue = $newPermissions[$key] ?? false;

            if ($oldValue !== $newValue) {
                $changes[] = sprintf('%s %s', $newValue ? 'Enabled' : 'Disabled', $label);
            }
        }

        $changeDescription = !empty($changes) ? 'Changed: ' . implode(', ', $changes) : 'No changes detected';

        AdminAuthController::logUserAction(
            $user,
            'Updated User Permissions',
            sprintf('Updated permissions for user "%s %s" - %s', $targetUser->first_name, $targetUser->last_name, $changeDescription)
        );

        // Update permissions
        $targetUser->permissions = $validated['permissions'];
        $targetUser->save();

        return response()->json([
            'success' => true,
            'message' => 'User permissions updated successfully'
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'message' => 'Failed to update user permissions',
            'error' => $e->getMessage()
        ], 500);
    }
}


public function login(Request $request)
{
    $credentials = $request->validate([
        'username' => 'required|string',
        'password' => 'required|string',
    ]);

    // MANUAL CREDENTIALS VERIFICATION
    $user = \App\Models\BarangayUser::where('username', $credentials['username'])->with(['barangay', 'position'])->first();

    if (!$user || !\Hash::check($credentials['password'], $user->password)) {
        return response()->json([
            'status' => 'error',
            'message' => 'Invalid credentials'
        ], 401);
    }

    // Check if user is approved
    if (!$user->is_approved) {
        return response()->json([
            'status' => 'error',
            'message' => 'Your account is pending approval. Please contact the administrator.'
        ], 403);
    }

    AdminAuthController::logUserAction($user, 'Login','User login to system');

    // CREATE SANCTUM TOKEN
    $token = $user->createToken('barangay_token')->plainTextToken;

    $cookie = cookie(
        'auth_token',
        $token,
        60 * 24, // 1 day
        null, null, true, true, false, 'None'
    );

        // Log user login

        // include user's permissions in login response for frontend to initialize
        return response()->json([
        'status' => 'success',
        'message' => 'Logged in successfully',
        //'user' => $user,
        'user' => [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'barangay_name' => $user->barangay->name,
            'position_name' => $user->position->name,
           'photo_url' => $user->photo_path
                ? asset("storage/{$user->photo_path}")
                : null, // Returns full URL like http://localhost/storage/profile-photos/filename.jpg
        ],
            'permissions' => $user->permissions ?? [
                'view' => true,
                'add' => true,
                'edit' => true,
                'delete' => false,
                'print' => true,
            ],
        'access_token' => $token,
        'token_type' => 'Bearer',
    ])->withCookie($cookie);
}

   public function logout(Request $request)
{
    try {
        // Get the authenticated user with the correct guard
        $user = $request->user('barangay'); // Explicitly specify guard

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'No authenticated user found'
            ], 401);
        }

        // Delete ALL tokens for this user to ensure complete logout
        $user->tokens()->delete();
        AdminAuthController::logUserAction($user, 'Logout','User logout from the System');

        return response()->json([
            'status' => true,
            'message' => 'Successfully logged out'
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'Logout failed',
            'error' => $e->getMessage()
        ], 500);
    }
}

public function user(Request $request)
{
    $user = $request->user()->load(['barangay', 'position']);

    return response()->json([
        'status' => 'success',
        'user' => [
            'id' => $user->id,
            'first_name' => $user->first_name ?? '',
            'middle_name' => $user->middle_name ?? '',
            'last_name' => $user->last_name ?? '',
            'suffix' => $user->suffix ?? '',
            'email' => $user->email ?? '',
            'username' => $user->username ?? '',
            'barangay_name' => $user->barangay->name ?? '',
            'position_name' => $user->position->name ?? '',
            'photo_path' => $user->photo_path ?? null,
            'photo_url' => $user->photo_path ? asset("storage/{$user->photo_path}") : null
        ],
        // expose permissions for currently authenticated user
        'permissions' => $user->permissions ?? [
            'view' => true,
            'add' => true,
            'edit' => true,
            'delete' => false,
            'print' => true,
        ]
    ]);
}

public function checkEmailExists(Request $request)
{
    $validated = $request->validate([
        'email' => 'required|email'
    ]);

    $user = BarangayUser::where('email', $validated['email'])->first();

    return response()->json([
        'exists' => $user !== null,
        'message' => $user ? 'Email found' : 'Email not found'
    ]);
}

public function resetPassword(Request $request)
{
    $validated = $request->validate([
        'email' => 'required|email',
        'password' => 'required|string|min:8|confirmed',
    ]);

    $user = BarangayUser::where('email', $validated['email'])->first();

    if (!$user) {
        return response()->json([
            'message' => 'Email not found in our database'
        ], 404);
    }

    // Update the user's password
    $user->password = Hash::make($validated['password']);
    $user->save();

    AdminAuthController::logUserAction($user, 'Reset Password','User reset their account password');

    return response()->json([
        'message' => 'Password reset successful',
        'user' => [
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name
        ]
    ]);
}

    /**
     * Update user profile
     */
    public function updateProfile(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'message' => 'Unauthorized'
                ], 401);
            }

            \Log::info('Profile update request for user ' . $user->id, [
                'request_data' => $request->all(),
                'user_id' => $user->id
            ]);

            $validated = $request->validate([
                'first_name' => 'required|string|max:255',
                'middle_name' => 'nullable|string|max:255',
                'last_name' => 'required|string|max:255',
                'suffix' => 'nullable|string|max:255',
                'email' => 'required|string|email|max:255|unique:barangay_users,email,' . $user->id,
                'username' => 'required|string|max:255|unique:barangay_users,username,' . $user->id,
                'photo_path' => 'nullable|string',
            ]);

            // Store original values for comparison
            $originalData = [
                'first_name' => $user->first_name,
                'middle_name' => $user->middle_name,
                'last_name' => $user->last_name,
                'suffix' => $user->suffix,
                'email' => $user->email,
                'username' => $user->username,
                'photo_path' => $user->photo_path,
            ];

            // Update user profile
            $user->update([
                'first_name' => $validated['first_name'],
                'middle_name' => $validated['middle_name'],
                'last_name' => $validated['last_name'],
                'suffix' => $validated['suffix'],
                'email' => $validated['email'],
                'username' => $validated['username'],
                'photo_path' => $validated['photo_path'] ?? $user->photo_path,
            ]);

            // Generate detailed change log
            $changes = [];

            // Check each field for changes
            if ($originalData['first_name'] !== $validated['first_name']) {
                $changes[] = "First Name: {$originalData['first_name']} → {$validated['first_name']}";
            }

            if ($originalData['middle_name'] !== $validated['middle_name']) {
                $originalMiddle = $originalData['middle_name'] ?: 'empty';
                $newMiddle = $validated['middle_name'] ?: 'empty';
                $changes[] = "Middle Name: {$originalMiddle} → {$newMiddle}";
            }

            if ($originalData['last_name'] !== $validated['last_name']) {
                $changes[] = "Last Name: {$originalData['last_name']} → {$validated['last_name']}";
            }

            if ($originalData['suffix'] !== $validated['suffix']) {
                $originalSuffix = $originalData['suffix'] ?: 'empty';
                $newSuffix = $validated['suffix'] ?: 'empty';
                $changes[] = "Suffix: {$originalSuffix} → {$newSuffix}";
            }

            if ($originalData['email'] !== $validated['email']) {
                $changes[] = "Email: {$originalData['email']} → {$validated['email']}";
            }

            if ($originalData['username'] !== $validated['username']) {
                $changes[] = "Username: {$originalData['username']} → {$validated['username']}";
            }

            if ($originalData['photo_path'] !== ($validated['photo_path'] ?? $user->photo_path)) {
                $changes[] = "Changed picture";
            }

            // Create detailed log message
            $logDetails = empty($changes) ? 'No changes detected' : implode(', ', $changes);

            // Log the profile update with detailed changes
            AdminAuthController::logUserAction(
                $user,
                'Updated Profile',
                $logDetails
            );

            $user = $user->load(['barangay', 'position']);

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'user' => [
                    'id' => $user->id,
                    'first_name' => $user->first_name ?? '',
                    'middle_name' => $user->middle_name ?? '',
                    'last_name' => $user->last_name ?? '',
                    'suffix' => $user->suffix ?? '',
                    'email' => $user->email ?? '',
                    'username' => $user->username ?? '',
                    'barangay_name' => $user->barangay->name ?? '',
                    'position_name' => $user->position->name ?? '',
                    'photo_path' => $user->photo_path ?? null,
                    'photo_url' => $user->photo_path ? asset("storage/{$user->photo_path}") : null
                ]
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::warning('Profile update validation failed for user ' . ($user->id ?? 'unknown'), [
                'errors' => $e->errors(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Profile update failed for user ' . ($user->id ?? 'unknown'), [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Change user password
     */
    public function changePassword(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'message' => 'Unauthorized'
                ], 401);
            }

            $validated = $request->validate([
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:8|confirmed',
            ]);

            // Verify current password
            if (!Hash::check($validated['current_password'], $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ], 400);
            }

            // Update password
            $user->update([
                'password' => Hash::make($validated['new_password'])
            ]);

            // Log the password change
            AdminAuthController::logUserAction(
                $user,
                'Changed Password',
                'User changed their account password'
            );

            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to change password',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getBarangayLogs() {
        $logs = DB::table('logs')
            ->join('barangay_users', 'logs.user_id', '=', 'barangay_users.id')
            ->join('barangays', 'barangay_users.barangay_id', '=', 'barangays.id')
            ->join('barangay_positions', 'barangay_users.position_id', '=', 'barangay_positions.id')
            ->where('barangay_users.barangay_id', Auth::user()->barangay_id)
            ->whereNotNull('logs.user_id')
            ->select(
                'logs.user_id as id',
                DB::raw('(SELECT TOP 1 logs2.fullname FROM logs logs2 WHERE logs2.user_id = logs.user_id AND CAST(logs2.created_at AS DATE) = CAST(logs.created_at AS DATE) ORDER BY logs2.created_at DESC) as fullname'),
                DB::raw('CAST(logs.created_at AS DATE) as log_date'),
                DB::raw('COUNT(logs.id) as total_logs'),
                'barangays.name as barangay',
                'barangay_positions.name as position'
            )
            ->groupBy(
                'logs.user_id',
                DB::raw('CAST(logs.created_at AS DATE)'),
                'barangays.name',
                'barangay_positions.name'
            )
            ->orderByDesc(DB::raw('CAST(logs.created_at AS DATE)'))
            ->get();

        return response()->json($logs);
    }

    /**
     * Heartbeat endpoint to keep user session alive
     */
    public function heartbeat(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'message' => 'Unauthorized'
                ], 401);
            }

            // Don't log heartbeat activity to keep logs clean

            return response()->json([
                'message' => 'Heartbeat received',
                'timestamp' => now(),
                'user_id' => $user->id
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error processing heartbeat: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Inactivity logout endpoint - deletes all tokens for the user
     */
    public function inactivityLogout(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'message' => 'No authenticated user found'
                ], 401);
            }

            // Delete ALL tokens for this user
            $user->tokens()->delete();

            // Log the inactivity logout
            AdminAuthController::logUserAction($user, 'Logout', 'Logged out due to inactivity');

            return response()->json([
                'status' => true,
                'message' => 'Successfully logged out due to inactivity'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Inactivity logout failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
