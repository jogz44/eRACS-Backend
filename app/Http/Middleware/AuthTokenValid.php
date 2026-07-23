<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AuthTokenValid
{
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Check for token in cookie or Authorization header
        $token = $request->cookie('auth_token') ?? $request->bearerToken();

        if (!$token) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized. Missing token.',
            ], 401);
        }

        // 2. Validate and fetch token
        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized. Invalid token.',
            ], 401);
        }

        // 3. Check if token is expired (24 hours from creation)
        $tokenAge = now()->diffInHours($accessToken->created_at);
        if ($tokenAge >= 24) {
            $accessToken->delete();
            return response()->json([
                'status' => false,
                'message' => 'Session expired. Please login again.',
            ], 401);
        }

        // 4. Check for 5-minute inactivity timeout (skip for heartbeat requests)
        if (!$request->is('*/heartbeat')) {
            if ($accessToken->last_used_at) {
                $inactiveMinutes = now()->diffInMinutes($accessToken->last_used_at);
                if ($inactiveMinutes >= 50) {
                    $accessToken->delete();
                    return response()->json([
                        'status' => false,
                        'message' => 'Session expired due to inactivity. Please login again.',
                    ], 401);
                }
            }
        }

        // 5. Update last used timestamp
        $accessToken->update(['last_used_at' => now()]);

        // 6. Set the authenticated user
        $user = $accessToken->tokenable;
        if ($user) {
            Auth::setUser($user);
        }

        // 7. Continue request
        $response = $next($request);

        // 7. Wrap JSON response only
        // Skip wrapping for file downloads
        if ($response instanceof BinaryFileResponse) {
            return $response;
        }

        if (
            $request->wantsJson() &&
            $response instanceof JsonResponse &&
            $response->getStatusCode() === 200
        ) {
            $originalData = $response->getData(true);

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
