<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Express;
use App\Services\ExpressService;
use Illuminate\Http\Request;
use Throwable;

class ExpressController extends Controller
{
    private ExpressService $expressService;
    public function index()
    {
        $Checks = $this->expressService->index();
        try {
            if (!$Checks) {
                return response()->json(
                    ["message" => "No Express"]
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

    public function store($request)
    {
        try {
            $user = auth()->user();
            $this->expressService->store($request, $user);
            return response()->json("created",201);
        } catch (Throwable $e) {
            return response()->json(
                ["message" => $e->getMessage()],
                200
            );
        }
    }


    public function show($id)
    {
        try {
            $response = $this->expressService->show($id);
            if (!$response) {
                return response()->json([
                    "message" => "No Express"
                ], 404);
            } else {
                return response()->json([
                    "message" => $response
                ], 404);
            }
        } catch (Throwable $e) {
            return response()->json(
                ["message" => $e->getMessage()],
                500
            );
        }
    }


    public function destroy($id)
    {
        try {
            $response = $this->expressService->destroy($id);
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
