<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cheque;
use App\Services\ChequeService;
use DB;
use Illuminate\Http\Request;
use Throwable;

class ChequeController extends Controller
{
    private ChequeService $chequeService;

    public function __construct(ChequeService $chequeService)
    {
        $this->chequeService = $chequeService;
    }
    public function index()
    {
        $Checks = $this->chequeService->index();
        try {
            if (!$Checks) {
                return response()->json(
                    ["message" => "No Product"]
                    ,
                    200
                );
            } else {
                return response()->json(
                    $Checks,
                    200
                );
            }
        } catch (Throwable $e) {
            return response()->json(
                ["message" => $e->getMessage()],
                500
            );

        }

    }

    public function store($request, $referenceType, $referenceId, $direction)
    {
        try {

        } catch (Throwable $e) {
            return response()->json(
                ["message" => $e->getMessage()],
                500
            );
        }
    }


    public function show($cheque)
    {
        return DB::table('checks')->where('id', $cheque)->first();
    }


    public function clear($id)
    {
        try {
            $response = $this->chequeService->clear($id);
            return response()->json([
                "status" => $response['status'],
                "message" => $response['message']
            ], $response['status'] ? 200 : 422);
        } catch (Throwable $e) {
            return response()->json(
                ["message" => $e->getMessage()],
                500
            );
        }
    }
    public function bounce($id)
    {
        try {
            $response = $this->chequeService->bounce($id);
            return response()->json([
                "status" => $response['status'],
                "message" => $response['message']
            ], $response['status'] ? 200 : 422);
        } catch (Throwable $e) {
            return response()->json(
                ["message" => $e->getMessage()],
                500
            );
        }
    }
}
