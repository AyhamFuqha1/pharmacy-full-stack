<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeploymentReadinessService;

class DeploymentReadinessController extends Controller
{
    public function __construct(private DeploymentReadinessService $service)
    {
    }

    public function show()
    {
        return response()->json($this->service->report());
    }
}
