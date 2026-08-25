<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdvancedAuditReportService;
use Illuminate\Http\Request;

class AdvancedAuditReportController extends Controller
{
    public function __construct(private AdvancedAuditReportService $service)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'user_id' => 'nullable|integer|exists:users,id',
            'table_name' => 'nullable|string|max:100',
            'action_type' => 'nullable|string|max:100',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json($this->service->generate($filters));
    }
}
