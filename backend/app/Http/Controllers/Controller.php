<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Pagination\Paginator;

abstract class Controller
{
    protected function success(mixed $data = null, int $status = 200, ?string $message = null): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message ?? 'OK', 'data' => $data], $status);
    }

    protected function collection(Paginator $paginator, ?string $message = null): JsonResponse
    {
        $payload = [
            'success' => true,
            'message' => $message ?? 'OK',
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => method_exists($paginator, 'lastPage') ? $paginator->lastPage() : null,
                'total' => method_exists($paginator, 'total') ? $paginator->total() : null,
            ],
        ];

        return response()->json($payload);
    }

    protected function failure(string $message, int $status, array $errors = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'errors' => (object) $errors], $status);
    }
}
