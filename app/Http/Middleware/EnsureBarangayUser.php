<?php

namespace App\Http\Middleware;

use App\Models\BarangayUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

class EnsureBarangayUser
{
    public function handle(Request $request, Closure $next): Response
    {
        // Prefer explicit barangay guard; fallback to default user
        $user = Auth::guard('barangay')->user() ?: Auth::user();

        if (!$user || !($user instanceof BarangayUser)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized. Barangay user required.',
            ], 401);
        }

        if (!$user->is_approved) {
            return response()->json([
                'status' => false,
                'message' => 'Account not approved.',
            ], 403);
        }

        return $next($request);
    }
}


