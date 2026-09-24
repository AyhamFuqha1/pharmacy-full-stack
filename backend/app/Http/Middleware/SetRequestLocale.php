<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetRequestLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $requested = strtolower(substr($request->header('Accept-Language', 'en'), 0, 2));
        app()->setLocale(in_array($requested, ['en', 'ar'], true) ? $requested : 'en');

        return $next($request);
    }
}
