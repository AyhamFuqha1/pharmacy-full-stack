<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProviderRequest;
use App\Http\Requests\UpdateProviderRequest;
use App\Services\ProviderService;
use Throwable;

class ProviderController extends Controller
{
    private ProviderService $providerService;

    public function __construct(ProviderService $providerService)
    {
        $this->providerService = $providerService;
    }

    public function index()
    {
        return response()->json($this->providerService->index(),200);
    }

    public function show($id)
    {
        $provider = $this->providerService->show($id);
        return $provider
            ? response()->json($provider,200)
            : response()->json(['message'=>'Provider not found'],404);
    }

    public function store(StoreProviderRequest $request)
    {
        $this->providerService->store($request);
        return response()->json(['message'=>'Created'],201);
    }

    public function update(UpdateProviderRequest $request,$id)
    {
        return $this->providerService->update($request,$id)
            ? response()->json(['message'=>'Updated'],200)
            : response()->json(['message'=>'Not found'],404);
    }

    public function destroy($id)
    {
        return $this->providerService->destroy($id)
            ? response()->json(['message'=>'Deleted'],200)
            : response()->json(['message'=>'Not found'],404);
    }

    public function incrementDept($id,$amount,$purchaseId)
    {
        $this->providerService->incrementDept($id,$amount,$purchaseId);
        return response()->json(['message'=>'Debt increased'],200);
    }

    public function decrementDept($id,$amount,$purchaseId)
    {
        $this->providerService->decrementDept($id,$amount,$purchaseId);
        return response()->json(['message'=>'Payment recorded'],200);
    }
}
