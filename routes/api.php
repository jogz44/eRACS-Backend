<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\DisbursementController;
use App\Http\Controllers\Library\AccountsLibController;
use App\Http\Controllers\Library\BankLibraryController;
use App\Http\Controllers\Transaction\AppropriationController;
use App\Http\Controllers\Transaction\ContinuingAppropriationController;
use App\Http\Controllers\ContinuingDisbursementController;
use App\Http\Controllers\BudgetAugmentationController;
use App\Http\Controllers\AdminReviewController;
use App\Http\Middleware\AuthTokenValid;
use App\Models\Barangay;
use App\Models\BarangayPosition;
use App\Models\Admin;
use App\Http\Controllers\OtpController;
use App\Http\Controllers\ReportController;


Route::prefix('barangay')->group(function () {
    // Barangays list endpoint
    Route::get('/barangays', function(Request $request) {
        $query = Barangay::query();

        if ($request->name) {
            $query->where('name', $request->name);
        }

        return response()->json($query->get(['id', 'name']));
    });

    // Barangay positions endpoint
    Route::get('/positions', function(Request $request) {
        $query = BarangayPosition::query();

        if ($request->name) {
            $query->where('name', $request->name);
        }

        return response()->json($query->get(['id', 'name']));
    });

    // Public routes
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/upload-photo', [AuthController::class, 'uploadPhoto']);
    Route::post('/check-email', [AuthController::class, 'checkEmailExists']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::post('/otp/generate', [OtpController::class, 'send']);
    Route::post('/otp/verify', [OtpController::class, 'verify']);
    Route::post('/otp/resend', [OtpController::class, 'resend']);

    Route::middleware(['auth.barangay'])->group(function () {
        // Route::middleware(['check.role'])->group(function () {
                    Route::post('/setlogs', [AdminAuthController::class, 'logUserActionRequest']);
        Route::get('/getlogs', [AuthController::class, 'getBarangayLogs']);
        Route::post('/heartbeat', [AuthController::class, 'heartbeat']);
        Route::post('/inactivity-logout', [AuthController::class, 'inactivityLogout']);
        // });
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', [AuthController::class, 'user']);
        Route::get('/users', [AuthController::class, 'getBarangayUsers']);
        Route::post('/users/{userId}/permissions', [AuthController::class, 'updateUserPermissions']);

        // Profile management routes
        Route::put('/update-profile', [AuthController::class, 'updateProfile']);
        Route::put('/change-password', [AuthController::class, 'changePassword']);

        //Accounts Library

        //Fiscal Years
        Route::get('fiscal-years', [AccountsLibController::class, 'getFiscalYears']);
        Route::post('fiscal-years', [AccountsLibController::class, 'createFiscalYear']);

        // Expense Classes
        Route::get('expense-classes', [AccountsLibController::class, 'getExpenseClasses']);
        Route::post('expense-classes', [AccountsLibController::class, 'createExpenseClass']);
        Route::put('expense-classes/{classId}', [AccountsLibController::class, 'updateClass']);
        Route::delete('expense-classes/{classId}', [AccountsLibController::class, 'deleteClass']);
        Route::patch('expense-classes/update-order', [AccountsLibController::class, 'updateClassOrder']);
        //
        Route::post('expense-classes/copy-to-year/{sourceYearId}',
        [AccountsLibController::class, 'copyToYear']);

        // Expense Types
        Route::get('expense-classes/{class}/types', [AccountsLibController::class, 'getExpenseTypes']);
        Route::post('expense-classes/{class}/types', [AccountsLibController::class, 'createExpenseType']);
        Route::put('expense-classes/{classId}/types/{typeId}', [AccountsLibController::class, 'updateExpenseType']);
        Route::delete('expense-classes/{classId}/types/{typeId}', [AccountsLibController::class, 'deleteType']);
        Route::patch('expense-classes/{classId}/types/update-order', [AccountsLibController::class, 'updateTypeOrder']);

        // Expense Items
        Route::get('expense-classes/{class}/types/{type}/items', [AccountsLibController::class, 'getExpenseItems']);
        Route::post('expense-classes/{class}/types/{type}/items', [AccountsLibController::class, 'createExpenseItem']);
        Route::put('expense-classes/{classId}/types/{typeId}/items/{itemId}', [AccountsLibController::class, 'updateItem']);
        Route::delete('expense-classes/{classId}/types/{typeId}/items/{itemId}', [AccountsLibController::class, 'deleteItem']);

        // Sub-Items
        Route::get('expense-classes/{class}/types/{type}/items/{item}/sub-items', [AccountsLibController::class, 'getSubItems']);
        Route::post('expense-classes/{class}/types/{type}/items/{item}/sub-items', [AccountsLibController::class, 'createSubItem']);
        Route::put('expense-classes/{classId}/types/{typeId}/items/{itemId}/sub-items/{subItemId}', [AccountsLibController::class, 'updateSubItem']);
        Route::delete('expense-classes/{classId}/types/{typeId}/items/{itemId}/sub-items/{subItemId}', [AccountsLibController::class, 'deleteSubItem']);

        //Banks Library
        Route::get('banks', [BankLibraryController::class, 'getBanks']);
        Route::post('banks', [BankLibraryController::class, 'createBank']);
        Route::put('banks/{bank}', [BankLibraryController::class, 'updateBank']);
        Route::delete('banks/{bank}', [BankLibraryController::class, 'deleteBank']);
        Route::get('banks/{bank}/cheques', [BankLibraryController::class, 'getBankCheques']);
        Route::post('banks/{bank}/cheques', [BankLibraryController::class, 'createCheque']);
        Route::get('banks/{bank}/available-cheques', [BankLibraryController::class, 'getAvailableBookletCheques']);

        Route::get('banks/{bank}/booklets', [BankLibraryController::class, 'getBankBooklets']);
        Route::post('banks/{bank}/booklets', [BankLibraryController::class, 'createBooklet']);
        // In routes/api.php
        Route::get('/booklets/{bookletId}/cheques', [BankLibraryController::class, 'getBookletCheques'])
        ->where('bookletId', '[0-9]+'); // Ensure numeric ID only



        //Transaction Appropriation
        // Budget endpoints
        Route::get('budgets', [AppropriationController::class, 'index']);
        // Add this above your existing budget routes
        Route::post('budgets/create', [AppropriationController::class, 'storeBudget']);
            // Dashboard summary endpoint
    Route::get('dashboard/summary', [AppropriationController::class, 'getDashboardSummary']);

    // Debug endpoint for troubleshooting
    Route::get('dashboard/debug', [AppropriationController::class, 'getDashboardDebug']);
        // Expense hierarchy
        Route::get('expense-hierarchy', [AppropriationController::class, 'getExpenseHierarchy']);
        // Appropriations for augmentation
        Route::get('appropriations', [AppropriationController::class, 'getAppropriationsForAugmentation']);
        // Allocation endpoints
        Route::get('budgets/{budget}/allocations', [AppropriationController::class, 'getBudgetAllocations']);
        Route::post('budgets/{budget}/allocate', [AppropriationController::class, 'saveAllocation']);
        Route::get('budgets/{id}/history', [AppropriationController::class, 'getAllocationHistory']);
        Route::patch('budgets/{budget}/allocations', [AppropriationController::class, 'updateAllocations']);
        // Recent Liquidated Disbursements
        Route::get('/disbursements/recent-liquidated', [DisbursementController::class, 'recentLiquidated']);
        // All Disbursements for barangay
        Route::get('disbursements', [DisbursementController::class, 'index']);
        // Create new disbursement
        Route::post('disbursements', [DisbursementController::class, 'store']);
        // Create new Reimbursement by Dan Steve<3
        Route::post('reimbursements/{id}', [DisbursementController::class, 'storeReimbursement']);
        // Update disbursement
        Route::put('disbursements/{id}', [DisbursementController::class, 'update']);
        // Get single disbursement
        Route::get('disbursements/{id}', [DisbursementController::class, 'show']);
        // Liquidate a disbursement
        Route::patch('disbursements/{id}/liquidate', [DisbursementController::class, 'liquidate']);
        // Delete a disbursement
        Route::delete('disbursements/{id}', [DisbursementController::class, 'destroy']);
        // Void workflow
        Route::post('disbursements/{id}/void-request', [DisbursementController::class, 'requestVoid']);
        Route::post('disbursements/{id}/void-approve', [DisbursementController::class, 'approveVoid']);
        Route::post('disbursements/{id}/void-reject', [DisbursementController::class, 'rejectVoid']);
        Route::post('disbursements/{id}/void-direct', [DisbursementController::class, 'voidDirect']);
        // Edit workflow (mirrors void workflow)
        Route::post('disbursements/{id}/edit-request', [DisbursementController::class, 'requestEdit']);
        Route::post('disbursements/{id}/edit-approve', [DisbursementController::class, 'approveEdit']);
        Route::post('disbursements/{id}/edit-reject', [DisbursementController::class, 'rejectEdit']);
        // Fetch OR Details for a disbursement
        Route::get('disbursements/{id}/or-details', [DisbursementController::class, 'getOrDetails']);
        // Save OR Details for a disbursement
        Route::post('disbursements/{id}/or-details', [DisbursementController::class, 'saveOrDetails']);
        // Delete individual OR Detail
        Route::delete('disbursements/{id}/or-details/{orDetailId}', [DisbursementController::class, 'deleteOrDetail']);
        // Upload OR photo
        Route::post('disbursements/or-photo/upload', [DisbursementController::class, 'uploadOrPhoto']);
        // Delete OR photo
        Route::delete('disbursements/or-photo/delete', [DisbursementController::class, 'deleteOrPhoto']);

        // Expense Details endpoints
        Route::get('expense-details', [DisbursementController::class, 'getExpenseDetails']);
        Route::post('expense-details', [DisbursementController::class, 'storeExpenseDetail']);
        Route::patch('expense-details/{id}', [DisbursementController::class, 'updateExpenseDetail']);
        Route::delete('expense-details/{id}', [DisbursementController::class, 'destroyExpenseDetail']);

        // DVnumber generation endpoint- by Dan Steve
        Route::get('generate-dvnumber', [DisbursementController::class, 'generateDvNumber']);

        // Budget Augmentation endpoints
        Route::apiResource('budget-augmentations', BudgetAugmentationController::class);

        // Supplemental Budget endpoints
        Route::get('unused-expenses', [AppropriationController::class, 'getUnusedExpenses']);
        Route::post('supplemental-budgets', [AppropriationController::class, 'createSupplementalBudget']);
        Route::get('supplemental-budgets', [AppropriationController::class, 'getSupplementalBudgets']);
        Route::get('fiscal-years', [AppropriationController::class, 'getFiscalYears']);


        // Report routes aka Preview and PDF download by Dan Steve
        Route::get('/report/rac', [ReportController::class, 'getRacReport']);
        Route::get('/report/sacb', [ReportController::class, 'getSacbReport']);

        // Particular route by Dan Steve
        Route::get('/particulars', [DisbursementController::class, 'getParticular']);

        // Continuing Appropriation
        Route::get('/continuing-appropriations', [ContinuingAppropriationController::class, 'index']);
        Route::post('/continuing-appropriations', [ContinuingAppropriationController::class, 'store']);
        Route::get('/continuing-appropriations/list', [ContinuingAppropriationController::class, 'getContinuingAppropriations']);
        Route::get('/continuing-appropriations/disbursement-accounts', [ContinuingAppropriationController::class, 'getContinuedAccountsForDisbursement']);
        Route::patch('/continuing-appropriations/{id}/status', [ContinuingAppropriationController::class, 'updateStatus']);
        Route::get('/continuing-appropriations/{id}/history', [ContinuingAppropriationController::class, 'getAllocationHistory']);
        Route::post('/continuing-appropriations/{id}/allocate', [ContinuingAppropriationController::class, 'commitAllocation']);

        // Continuing Disbursement
        Route::get('/continuing-disbursements', [ContinuingDisbursementController::class, 'index']);
        Route::post('/continuing-disbursements', [ContinuingDisbursementController::class, 'store']);
        Route::get('/continuing-disbursements/{id}', [ContinuingDisbursementController::class, 'show']);
        Route::put('/continuing-disbursements/{id}', [ContinuingDisbursementController::class, 'update']);
        Route::delete('/continuing-disbursements/{id}', [ContinuingDisbursementController::class, 'destroy']);

        // Continuing Disbursement OR Details
        Route::get('/continuing-disbursements/{id}/or-details', [ContinuingDisbursementController::class, 'getOrDetails']);
        Route::post('/continuing-disbursements/{id}/or-details', [ContinuingDisbursementController::class, 'saveOrDetails']);
        Route::delete('/continuing-disbursements/{id}/or-details/{orDetailId}', [ContinuingDisbursementController::class, 'deleteOrDetail']);
        // Continuing Disbursement OR Photo Upload
        Route::post('/continuing-disbursements/or-photo/upload', [ContinuingDisbursementController::class, 'uploadOrPhoto']);


    });

});

Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']);


    // Just use Sanctum's default auth
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::post('/inactivity-logout', [AdminAuthController::class, 'inactivityLogout']);
        Route::post('/setlogs', [AdminAuthController::class, 'logAdminAction']);
        Route::post('/heartbeat', [AdminAuthController::class, 'heartbeat']);

        // User management endpoints
        Route::get('/users/pending', [AdminAuthController::class, 'getPendingUsers']);
        Route::get('/users/accepted', [AdminAuthController::class, 'getAcceptedUsers']);
        Route::patch('/users/{user}/approve', [AdminAuthController::class, 'approveUser']);
        Route::delete('/users/{user}', [AdminAuthController::class, 'deleteUser']);

        // Admin appropriation endpoints - view only
        Route::get('/budgets', [AppropriationController::class, 'adminIndex']);
        Route::get('/budgets/{budget}/allocations', [AppropriationController::class, 'getBudgetAllocations']);
        Route::get('/budgets/{id}/history', [AppropriationController::class, 'getAllocationHistory']);
        Route::get('/expense-hierarchy', [AppropriationController::class, 'getExpenseHierarchy']);

        // Admin disbursement endpoints - view only
        Route::get('/disbursements', [DisbursementController::class, 'adminIndex']);
        // Admin can fetch expense details for a selected barangay
        Route::get('/expense-details', [DisbursementController::class, 'getExpenseDetails']);
        // Admin can view OR details for any disbursement
        Route::get('/disbursements/{id}/or-details', [DisbursementController::class, 'getOrDetails']);
        Route::get('disbursements/{id}', [DisbursementController::class, 'show']);

        // Admin report endpoints
        Route::get('/report/sacb', [ReportController::class, 'getSacbReport']);
        Route::get('/report/rac', [ReportController::class, 'getRacReport']);

        // Admin banks endpoint (list all banks for selection in admin UI)
        Route::get('/banks', [\App\Http\Controllers\Library\BankLibraryController::class, 'getBanks']);

        // Admin augmentation endpoints - view only
        Route::get('/augmentations', [BudgetAugmentationController::class, 'adminIndex']);
        // Admin can view individual augmentation
        Route::get('/augmentations/{id}', [BudgetAugmentationController::class, 'show']);


        // Admin supplemental budget endpoints
        Route::get('/unused-expenses', [AppropriationController::class, 'getUnusedExpenses']);
        Route::get('/supplemental-budgets', [AppropriationController::class, 'getSupplementalBudgets']);
        Route::get('/fiscal-years', [AppropriationController::class, 'getFiscalYears']);


        // Admin review endpoints
        Route::post('/reviews', [AdminReviewController::class, 'store']);
        Route::get('/reviews/check', [AdminReviewController::class, 'checkReview']);
        Route::get('/reviews', [AdminReviewController::class, 'getReviews']);
        Route::post('/reviews/bulk', [AdminReviewController::class, 'getBulkReviews']);

    });

    // Dashboard Routes updated
    // Outered from sanctum middleware
    Route::get('/per-barangay-budgets',[AdminAuthController::class, 'getPerBarangaysBudgets']);

    // Admin user access and logs endpoints
    // Route::middleware(['check.role'])->group(function () {
        Route::get('/users', [AdminAuthController::class, 'getUsersWithPermissions']);
        // Admin view of particulars
        Route::get('/particulars', [DisbursementController::class, 'getParticular']);
        // Fixed path to avoid double 'admin' in route: now /api/admin/user-access/{id}
        Route::post('/user-access/{id}', [AdminAuthController::class, 'updateUserPermissions']);
        Route::get('/can-manage-access', [AdminAuthController::class, 'canManageUserAccessCheck']);
        Route::get('/logs', [AdminAuthController::class, 'getAllLogs']);
        Route::get('/admin-logs', [AdminAuthController::class, 'getAdminLogs']);

        // Admin Individual Log Open
        Route::get('/logs/{user}/{day}', [AdminAuthController::class, 'getUserLogs']);
    // });
});
