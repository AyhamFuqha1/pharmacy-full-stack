<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use App\Exceptions\AccountingPeriodException;
use App\Exceptions\PrescriptionDispensingException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [\App\Http\Middleware\SetRequestLocale::class]);
        $middleware->alias([
            'password.must-change' => \App\Http\Middleware\EnsurePasswordChanged::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $exception, $request) {
            if (!$request->is('api/*')) return null;
            if ($exception instanceof ValidationException) {
                return response()->json(['success' => false, 'message' => __('api.validation_failed'), 'errors' => $exception->errors()], 422);
            }
            if ($exception instanceof \Illuminate\Auth\AuthenticationException) {
                return response()->json(['success' => false, 'message' => __('api.unauthenticated'), 'errors' => (object) []], 401);
            }
            if ($exception instanceof AuthorizationException) {
                return response()->json(['success' => false, 'message' => __('api.forbidden'), 'errors' => (object) []], 403);
            }
            if ($exception instanceof ModelNotFoundException) {
                return response()->json(['success' => false, 'message' => __('api.not_found'), 'errors' => (object) []], 404);
            }
            if ($exception instanceof AccountingPeriodException) {
                return response()->json(['success' => false, 'message' => $exception->getMessage(), 'errors' => (object) []], 409);
            }
            if ($exception instanceof PrescriptionDispensingException) {
                return response()->json(['success' => false, 'message' => $exception->getMessage(), 'errors' => (object) []], 409);
            }
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
            $message = match ($status) {
                403 => __('api.forbidden'),
                404 => __('api.not_found'),
                default => $status >= 500 ? __('api.server_error') : __('api.request_failed'),
            };
            return response()->json(['success' => false, 'message' => $message, 'errors' => (object) []], $status);
        });
    })->create();
