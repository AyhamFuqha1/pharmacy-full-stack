<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        $authorized = collect($permissions)
            ->contains(fn (string $permission) => $request->user()?->hasPermission($permission));

        abort_unless($authorized, 403);
        return $next($request);
    }
}
