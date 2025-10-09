<?php

namespace App\Http\Controllers;

use App\Models\AdminReview;
use App\Models\TranAppropriation;
use App\Models\Disbursement;
use App\Models\BudgetAugmentation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class AdminReviewController extends Controller
{
    /**
     * Store a new admin review.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reviewable_type' => 'required|string|in:App\Models\TranAppropriation,App\Models\Disbursement,App\Models\BudgetAugmentation',
            'reviewable_id' => 'required|integer|min:1',
            'remarks' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Check if the reviewable item exists
            $reviewableModel = $request->reviewable_type;
            $reviewableItem = $reviewableModel::find($request->reviewable_id);
            
            if (!$reviewableItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Item not found'
                ], 404);
            }

            // Check if admin already reviewed this item
            $existingReview = AdminReview::where('admin_id', Auth::id())
                ->where('reviewable_type', $request->reviewable_type)
                ->where('reviewable_id', $request->reviewable_id)
                ->first();

            if ($existingReview) {
                return response()->json([
                    'success' => false,
                    'message' => 'You have already reviewed this item'
                ], 409);
            }

            // Create the review (timestamps will set created_at automatically)
            $review = AdminReview::create([
                'admin_id' => Auth::id(),
                'reviewable_type' => $request->reviewable_type,
                'reviewable_id' => $request->reviewable_id,
                'remarks' => $request->remarks,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Review saved successfully',
                'data' => [
                    'id' => $review->id,
                    'remarks' => $review->remarks,
                    'created_at' => $review->created_at->format('Y-m-d H:i:s'),
                    'admin_name' => Auth::user()->name ?? Auth::user()->email,
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save review: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get reviews for a specific item.
     */
    public function getReviews(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reviewable_type' => 'required|string|in:App\Models\TranAppropriation,App\Models\Disbursement,App\Models\BudgetAugmentation',
            'reviewable_id' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $reviews = AdminReview::with('admin')
                ->where('reviewable_type', $request->reviewable_type)
                ->where('reviewable_id', $request->reviewable_id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($review) {
                    return [
                        'id' => $review->id,
                        'remarks' => $review->remarks,
                        'created_at' => $review->created_at->format('Y-m-d H:i:s'),
                        'admin_name' => $review->admin->name ?? $review->admin->email,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $reviews
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch reviews: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if current admin has reviewed a specific item.
     */
    public function checkReview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reviewable_type' => 'required|string|in:App\Models\TranAppropriation,App\Models\Disbursement,App\Models\BudgetAugmentation',
            'reviewable_id' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $review = AdminReview::where('admin_id', Auth::id())
                ->where('reviewable_type', $request->reviewable_type)
                ->where('reviewable_id', $request->reviewable_id)
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'is_reviewed' => $review !== null,
                    'review' => $review ? [
                        'id' => $review->id,
                        'remarks' => $review->remarks,
                        'created_at' => $review->created_at->format('Y-m-d H:i:s'),
                    ] : null
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check review: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all reviews for multiple items (for bulk checking).
     */
    public function getBulkReviews(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|array',
            'items.*.reviewable_type' => 'required|string|in:App\Models\TranAppropriation,App\Models\Disbursement,App\Models\BudgetAugmentation',
            'items.*.reviewable_id' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $reviews = [];
            
            foreach ($request->items as $item) {
                $review = AdminReview::where('admin_id', Auth::id())
                    ->where('reviewable_type', $item['reviewable_type'])
                    ->where('reviewable_id', $item['reviewable_id'])
                    ->first();

                $reviews[] = [
                    'reviewable_type' => $item['reviewable_type'],
                    'reviewable_id' => $item['reviewable_id'],
                    'is_reviewed' => $review !== null,
                    'review' => $review ? [
                        'id' => $review->id,
                        'remarks' => $review->remarks,
                        'created_at' => $review->created_at->format('Y-m-d H:i:s'),
                    ] : null
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $reviews
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch bulk reviews: ' . $e->getMessage()
            ], 500);
        }
    }
}