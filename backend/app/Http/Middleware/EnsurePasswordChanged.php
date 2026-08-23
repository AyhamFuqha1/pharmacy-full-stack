<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsurePasswordChanged
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $isPasswordChangeRoute = $request->is('api/change-password') || $request->is('change-password');
        $isLogoutRoute = $request->is('api/logout') || $request->is('logout');

        if ($user->force_change_password && ! $isPasswordChangeRoute && ! $isLogoutRoute) {
            return response()->json([
                'message' => 'You must change your password before continuing.',
            ], 403);
        }

        return $next($request);
    }
}
