<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DashBoard;
use App\Services\DashBoardService;
use Illuminate\Http\Request;
use Throwable;

class DashBoardController extends Controller
{
    private DashBoardService $dashBoardService;
    public function __construct(DashBoardService $dashBoardService)
    {
        $this->dashBoardService = $dashBoardService;
    }


    public function index()
    {
        try {
               return response()->json($this->dashBoardService->index());
        } catch (Throwable $e) {
            return response()->json(
                ["message" => $e->getMessage()],
                500
            );

        }
    }


}
