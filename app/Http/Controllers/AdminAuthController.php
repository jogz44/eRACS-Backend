<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use App\Models\Admin;
use App\Models\Barangay;
use App\Models\BarangayUser;
use App\Models\Budget;

//use Symfony\Component\HttpFoundation\Cookie;

class AdminAuthController extends Controller  // <-- This is crucial
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|string',
            'password' => 'required|string'
        ]);

        $admin = Admin::where('email', $credentials['email'])->first();

        if (!$admin || !Hash::check($credentials['password'], $admin->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid credentials'
            ], 401);
        }

        $token = $admin->createToken('admin-token', ['admin'])->plainTextToken;

        // Log admin login to admin_logs table
        DB::table('admin_logs')->insert([
            'admin_id' => $admin->id,
            'fullname' => $admin->name ?? 'Admin',
            'activity' => 'Login',
            'details' => 'Admin login to system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cookie = cookie(
            'admin_token',
            $token,
            1440, // 1 day in minutes
            null,
            null,
            config('app.env') === 'production',
            true,
            false,
            'Lax'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Logged in successfully',
            'token' => $token,
            'admin' => [
                'id' => $admin->id,
                'email' => $admin->email,
                'name' => $admin->name ?? 'Admin',
                'role' => $admin->role
            ]
        ])->withCookie($cookie);
    }

    public function logout(Request $request)
    {
        $admin = $request->user('admin');

        // Log admin logout to admin_logs table
        if ($admin) {
            DB::table('admin_logs')->insert([
                'admin_id' => $admin->id,
                'fullname' => $admin->name,
                'activity' => 'Logout',
                'details' => 'Admin logout from system',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Delete ALL tokens for this admin to ensure complete logout
        $admin->tokens()->delete();
        $cookie = Cookie::forget('admin_token');

        return response()->json([
            'status' => 'success',
            'message' => 'Successfully logged out'
        ])->withCookie($cookie);
    }

    // Get pending users (not approved)
    public function getPendingUsers()
    {
        $users = BarangayUser::where('is_approved', false)
            ->with(['barangay', 'position'])
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'barangay' => $user->barangay->name,
                    'position' => $user->position->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'avatar' => $user->photo_url,
                    'created_at' => $user->created_at->format('F j, Y'),
                ];
            });

        return response()->json($users);
    }

    // Get accepted users (approved)
    public function getAcceptedUsers()
    {
        $users = BarangayUser::where('is_approved', true)
            ->with(['barangay', 'position'])
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'barangay' => $user->barangay->name,
                    'position' => $user->position->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'avatar' => $user->photo_url,
                    'permissions' => $user->permissions,
                    'created_at' => $user->created_at->format('F j, Y'),
                ];
            });

        return response()->json($users);
    }

    // Approve a user
    public function approveUser(Request $request, BarangayUser $user)
    {
        // Set default permissions - only view access for new users
        $defaultPermissions = [
            'view' => true,
            'add' => false,
            'edit' => false,
            'delete' => false,
            'print' => false,
        ];

        $user->update([
            'is_approved' => true,
            'permissions' => $defaultPermissions
        ]);

        return response()->json([
            'message' => 'User approved successfully',
            'user' => [
                'id' => $user->id,
                'name' => $user->first_name . ' ' . $user->last_name,
                'username' => $user->username,
                'email' => $user->email,
            ]
        ]);
    }

    // Delete a user
    public function deleteUser(BarangayUser $user)
    {
        try {
            $userName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->username ?? (string)$user->id);

            // Clean up dependent records that may have FKs
            // Remove user activity logs (if any)
            DB::table('logs')->where('user_id', $user->id)->delete();

            // Revoke tokens for this user if any
            try {
                $user->tokens()->delete();
            } catch (\Throwable $t) {
                // ignore if tokens relation not configured
            }

            // Finally delete the user
            $user->delete();

            return response()->json([
                'message' => "User {$userName} deleted successfully"
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to delete user',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Get all users with permissions
    public function getUsersWithPermissions() {
        return BarangayUser::with('position')
            ->select('id', 'first_name', 'last_name', 'username', 'position_id', 'permissions')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'username' => $user->username,
                    'position' => $user->position->name,
                    'permissions' => $user->permissions,
                ];
            });
    }

    // Update user permissions
    public function updateUserPermissions(Request $request, $id) {
        try {
            $user = BarangayUser::findOrFail($id);

            // Check if the requesting user has permission to manage user access
            $requestingUser = $request->user('barangay') ?? $request->user('admin');

            if (!$this->canManageUserAccess($requestingUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not have permission to manage user access. Only Barangay Captain, SK Chairperson, and Admin can manage user permissions.'
                ], 403);
            }

            // Validate the permissions data
            $validated = $request->validate([
                'permissions' => 'required|array',
                'permissions.view' => 'boolean',
                'permissions.add' => 'boolean',
                'permissions.edit' => 'boolean',
                'permissions.delete' => 'boolean',
                'permissions.print' => 'boolean',
            ]);

            $user->permissions = $validated['permissions'];
            $user->save();

            return response()->json([
                'status' => 'success',
                'message' => 'User permissions updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update user permissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if the user can manage user access permissions
     * Only Barangay Captain, SK Chairperson, and Admin can manage permissions
     */
    private function canManageUserAccess($user) {
        if (!$user) {
            return false;
        }

        // Check if user is admin (from admin guard)
        if ($user instanceof \App\Models\Admin) {
            return true;
        }

        // Check if user is barangay user with appropriate role
        if ($user instanceof \App\Models\BarangayUser) {
            // Check by role field
            if ($user->role === 'admin') {
                return true;
            }

            // Check by position name
            $position = $user->position ? $user->position->name : '';
            $positionLower = strtolower($position);

            return strpos($positionLower, 'captain') !== false ||
                   strpos($positionLower, 'chairperson') !== false ||
                   strpos($positionLower, 'barangay captain') !== false ||
                   strpos($positionLower, 'sk chairperson') !== false;
        }

        return false;
    }

    /**
     * Check if current user can manage user access permissions
     * This endpoint can be called from frontend to determine UI visibility
     */
    public function canManageUserAccessCheck(Request $request) {
        $user = $request->user('barangay') ?? $request->user('admin');
        $canManage = $this->canManageUserAccess($user);

        return response()->json([
            'can_manage_access' => $canManage,
            'user_role' => $user ? ($user->role ?? ($user->position ? $user->position->name : 'Unknown')) : 'Not authenticated'
        ]);
    }

    // Get logs
    public function getAllLogs() {
        // Get user logs
        $userLogs = DB::table('logs')
            ->join('barangay_users', 'logs.user_id', '=', 'barangay_users.id')
            ->join('barangays', 'barangay_users.barangay_id', '=', 'barangays.id')
            ->join('barangay_positions', 'barangay_users.position_id', '=', 'barangay_positions.id')
            ->select(
                'logs.user_id as id',
                DB::raw('(SELECT TOP 1 logs2.fullname FROM logs logs2 WHERE logs2.user_id = logs.user_id AND CAST(logs2.created_at AS DATE) = CAST(logs.created_at AS DATE) ORDER BY logs2.created_at DESC) as fullname'),
                DB::raw('CAST(logs.created_at AS DATE) as log_date'),
                DB::raw('COUNT(logs.id) as total_logs'),
                'barangays.name as barangay',
                'barangay_positions.name as position',
                DB::raw("'user' as user_type"),
                DB::raw("NULL as admin_role")
            )
            ->whereNotNull('logs.fullname') // Ensure fullname is not null
            ->where('logs.fullname', '!=', '') // Ensure fullname is not empty
            ->where('logs.fullname', '!=', ' ') // Ensure fullname is not just a space
            ->groupBy(
                'logs.user_id',
                DB::raw('CAST(logs.created_at AS DATE)'),
                'barangays.name',
                'barangay_positions.name'
            );

        // Get admin logs
        $adminLogs = DB::table('admin_logs')
            ->join('admins', 'admin_logs.admin_id', '=', 'admins.id')
            ->select(
                'admin_logs.admin_id as id',
                DB::raw('(SELECT TOP 1 admin_logs2.fullname FROM admin_logs admin_logs2 WHERE admin_logs2.admin_id = admin_logs.admin_id AND CAST(admin_logs2.created_at AS DATE) = CAST(admin_logs.created_at AS DATE) ORDER BY admin_logs2.created_at DESC) as fullname'),
                DB::raw('CAST(admin_logs.created_at AS DATE) as log_date'),
                DB::raw('COUNT(admin_logs.id) as total_logs'),
                DB::raw("'Admin' as barangay"),
                DB::raw("'Administrator' as position"),
                DB::raw("'admin' as user_type"),
                'admins.role as admin_role'
            )
            ->whereNotNull('admin_logs.fullname') // Ensure fullname is not null
            ->where('admin_logs.fullname', '!=', '') // Ensure fullname is not empty
            ->where('admin_logs.fullname', '!=', ' ') // Ensure fullname is not just a space
            ->groupBy(
                'admin_logs.admin_id',
                DB::raw('CAST(admin_logs.created_at AS DATE)'),
                'admins.role'
            );

        // Combine and order by date (newest first)
        $combinedLogs = $userLogs->union($adminLogs)
            ->orderByDesc('log_date')
            ->get();

        return response()->json($combinedLogs);
    }

    // Get admin logs with filtering
    public function getAdminLogs(Request $request) {
        $adminId = $request->query('admin_id');
        $date = $request->query('date');

        \Log::info("getAdminLogs called with adminId: {$adminId}, date: {$date}");

        $query = DB::table('admin_logs')
            ->join('admins', 'admin_logs.admin_id', '=', 'admins.id')
            ->select(
                'admin_logs.id',
                'admin_logs.activity',
                'admin_logs.details',
                'admin_logs.created_at',
                'admin_logs.fullname'
            );

        // Filter by admin_id if provided
        if ($adminId) {
            $query->where('admin_logs.admin_id', $adminId);
        }

        // Filter by date if provided
        if ($date) {
            $query->whereDate('admin_logs.created_at', $date);
        }

        $logs = $query->orderByDesc('admin_logs.created_at')->get();

        \Log::info("Admin logs found: " . $logs->count());

        return response()->json($logs);
    }

    // Get individual user logs
    public function getUserLogs(Request $request, $userId, $day) {
        $userType = $request->query('user_type', 'user'); // Default to user if not specified

        \Log::info("getUserLogs called with userId: '{$userId}' (type: " . gettype($userId) . "), day: '{$day}', userType: '{$userType}'");

        if ($userType === 'admin') {
            // This is an admin user, get logs from admin_logs table
            $logs = DB::table('admin_logs')
                ->select(
                    'admin_logs.id',
                    'admin_logs.activity',
                    'admin_logs.details',
                    'admin_logs.created_at',
                    'admin_logs.fullname'
                )
                ->where('admin_logs.admin_id', $userId)
                ->whereDate('admin_logs.created_at', $day)
                ->orderByDesc('admin_logs.created_at')
                ->get();

            \Log::info("Admin logs found: " . $logs->count());
        } else {
            // This is a regular user, get logs from logs table
            $logs = DB::table('logs')
                ->select(
                    'logs.id',
                    'logs.activity',
                    'logs.details',
                    'logs.created_at',
                    'logs.fullname'
                )
                ->where('logs.user_id', $userId)
                ->whereDate('logs.created_at', $day)
                ->orderByDesc('logs.created_at')
                ->get();

            \Log::info("User logs found: " . $logs->count());
        }

        return response()->json($logs);
    }

    // Helper to log user actions (can be called from other controllers)
    public static function logUserAction($user, $activity, $details = null) {
        DB::table('logs')->insert([
            'user_id' => $user->id,
            'fullname' => $user->first_name . ' ' . $user->last_name,
            'activity' => $activity,
            'details' => $details,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Log admin actions
    public function logAdminAction(Request $request) {
        $validated = $request->validate([
            'activity' => 'required|string',
            'details' => 'nullable|string'
        ]);

        // Get the authenticated admin
        $admin = $request->user();
        if (!$admin || !($admin instanceof Admin)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        DB::table('admin_logs')->insert([
            'admin_id' => $admin->id,
            'fullname' => $admin->name ?? 'Admin',
            'activity' => $validated['activity'],
            'details' => $validated['details'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Action logged successfully']);
    }

    // Log user actions (instance method for route)
    public function logUserActionRequest(Request $request) {
        $validated = $request->validate([
            'activity' => 'required|string',
            'details' => 'nullable|string'
        ]);

        $user = $request->user();

        // Ensure we have a proper fullname
        $firstName = trim($user->first_name ?? '');
        $lastName = trim($user->last_name ?? '');
        $fullname = trim($firstName . ' ' . $lastName);

        // If fullname is empty, try to get it from the user object or use a fallback
        if (empty($fullname)) {
            $fullname = $user->fullname ?? $user->name ?? 'Unknown User';
        }

        DB::table('logs')->insert([
            'user_id' => $user->id,
            'fullname' => $fullname,
            'activity' => $validated['activity'],
            'details' => $validated['details'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Action logged successfully']);
    }
    public static function getPerBarangaysBudgets()
    {
        // $data = DB::table('barangays')
        //     ->leftJoin('budgets', 'barangays.id', '=', 'budgets.barangay_id')
        //     ->select(
        //         'barangays.id',
        //         'barangays.name as barangay_name',
        //         DB::raw('COALESCE(SUM(budgets.original_amount), 0) as total_original_amount'),
        //         DB::raw('COALESCE(SUM(budgets.current_amount), 0) as total_current_amount'),
        //         DB::raw('COUNT(budgets.id) as total_budget_entries')
        //     )
        //     ->groupBy('barangays.id', 'barangays.name')
        //     ->orderBy('barangay_name', 'asc')
        //     ->get();

        // {
        //     "id": "1",
        //     "barangay_name": "Apokon",
        //     "total_original_amount": "9000000.00",
        //     "total_current_amount": "8505984.00",
        //     "total_budget_entries": "6"
        // },
        $data = Barangay::with(['budget' => function ($q) {
                $q->whereHas('fiscalYear', function ($q2) {
                    $q2->where('year', now()->year);
                });
            }])
            ->with(['disbursement' => function ($q) {
                $q->whereYear('date', now()->year);
            }])
            ->get()
            ->map(function ($barangay) {
                $totalAnnual = $barangay->budget->where('budget_type', 'annual')->sum('original_amount');
                $totalSupplemental = $barangay->budget->where('budget_type', 'supplemental')->sum('original_amount');
                $totalExpenses= $barangay->disbursement->sum('dv_amount');
                $totalEntries = $barangay->budget->count();

                $query = Budget::with(['tranAppropriations', 'fiscalYear']);
                $query->where('barangay_id', $barangay->id);

                $query->whereHas('fiscalYear', function($q) {
                    $q->where('year', now()->year);
                });
                $budgets = $query->get();

                $totalAppropriation = $budgets
                    ->filter(function ($budget) {
                        // ✅ Always pull the raw column, not the relation
                        $budgetType = strtolower((string) $budget->getRawOriginal('budget_type'));
                        $description = strtolower((string) $budget->description);

                        return str_contains($description, 'annual') || $budgetType === 'annual';
                    })
                    ->reduce(function ($sum, $budget) {
                        return $sum + (float) $budget->original_amount + (float) $budget->augmentation;
                    }, 0);


                // 2. totalObligation → allocations from all budgets
                $totalObligation = $budgets->reduce(function($sum, $budget) {
                    return $sum + $budget->tranAppropriations->sum('amount');
                }, 0);

                // 3. totalBalance
                $totalBalance = $totalAppropriation - $totalObligation;

                // 4. totalExpense
                $totalExpense  = $totalObligation - $totalExpenses;

                return [
                    'id' => $barangay->id,
                    'barangay_name' => $barangay->name,
                    'total_budget' => number_format($totalAnnual, 2, '.', ''),
                    'total_supp' => number_format($totalSupplemental, 2, '.', ''),
                    'total_expenses' => number_format($totalExpenses, 2, '.', ''),
                    'total_entries' => $totalEntries,
                    'total_balance' => number_format($totalExpense, 2, '.', ''),
                ];
            })
            ->sortBy('barangay_name')
            ->values();

        return response()->json($data);
    }

    /**
     * Heartbeat endpoint to keep admin session alive
     */
    public function heartbeat(Request $request)
    {
        try {
            $admin = Auth::user();

            if (!$admin) {
                return response()->json([
                    'message' => 'Unauthorized'
                ], 401);
            }

            // Don't log heartbeat activity to keep logs clean

            return response()->json([
                'message' => 'Heartbeat received',
                'timestamp' => now(),
                'admin_id' => $admin->id
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error processing heartbeat: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Inactivity logout endpoint - deletes all tokens for the admin
     */
    public function inactivityLogout(Request $request)
    {
        try {
            $admin = Auth::user();

            if (!$admin) {
                return response()->json([
                    'message' => 'No authenticated admin found'
                ], 401);
            }

            // Delete ALL tokens for this admin
            $admin->tokens()->delete();

            // Log the inactivity logout to admin_logs table
            DB::table('admin_logs')->insert([
                'admin_id' => $admin->id,
                'fullname' => $admin->name ?? 'Admin',
                'activity' => 'Logout',
                'details' => 'Logged out due to inactivity',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

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
