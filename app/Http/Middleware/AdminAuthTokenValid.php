<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Laravel\Sanctum\PersonalAccessToken;
use Carbon\Carbon;

class AdminAuthTokenValid
{
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Check for token in cookie or Authorization header
        $token = $request->cookie('admin_auth_token') ?? $request->bearerToken();

        if (!$token) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized. Missing admin token.',
                'code' => 'MISSING_ADMIN_TOKEN'
            ], 401);
        }

        // 2. Validate and fetch token
        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized. Invalid admin token.',
                'code' => 'INVALID_ADMIN_TOKEN'
            ], 401);
        }

        // 3. Check for session timeout (15 minutes of inactivity)
        $timeoutMinutes = 15; // Increase timeout to 15 minutes for better UX
        
        if ($accessToken->last_used_at) {
            $minutesSinceLastUse = Carbon::parse($accessToken->last_used_at)->diffInMinutes(now());
            
            if ($minutesSinceLastUse >= $timeoutMinutes) {
                // Session expired due to inactivity
                $accessToken->delete();
                
                return response()->json([
                    'status' => false,
                    'message' => 'Admin session expired due to inactivity. Please login again.',
                    'code' => 'ADMIN_SESSION_EXPIRED'
                ], 401);
            }
        }

        // 4. Update last_used_at timestamp
        $accessToken->forceFill(['last_used_at' => now()])->save();

        // 5. Continue request
        $response = $next($request);

        // 6. Wrap JSON response
        if ($request->wantsJson() && $response->getStatusCode() === 200) {
            $originalData = json_decode($response->content(), true) ?? [];

            if (!isset($originalData['status'])) {
                $response->setData([
                    'status' => true,
                    'message' => 'Request successful',
                    'data' => $originalData,
                ]);
            }
        }

        return $response;
    }
}
