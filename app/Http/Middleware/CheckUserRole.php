<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\BarangayPosition;

class CheckUserRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Get the user's position
        $position = BarangayPosition::find($user->position_id);

        if (!$position) {
            return response()->json(['message' => 'Invalid user position'], 403);
        }

        $restrictedPositions = [
            'Brgy. Treasurer',
            'SK Chairperson',
            'SK Treasurer'
        ];

        // Check if user's position is in the restricted list
        if (in_array($position->name, $restrictedPositions)) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        return $next($request);
    }
}
