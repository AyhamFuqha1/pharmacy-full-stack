<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProviderRequest;
use App\Http\Requests\UpdateProviderRequest;
use App\Services\ProviderService;
use Illuminate\Http\Request;
use Throwable;

class ProviderController extends Controller
{
    private ProviderService $providerService;

    public function __construct(ProviderService $providerService)
    {
        $this->providerService = $providerService;
    }

    public function index(Request $request)
    {
        $request->validate(['archive_status' => 'nullable|in:active,archived,all', 'created_from' => 'nullable|date', 'created_to' => 'nullable|date|after_or_equal:created_from', 'sort' => 'nullable|in:id,name,created_at', 'direction' => 'nullable|in:asc,desc']);
        return $this->collection($this->providerService->index($request), 'Providers retrieved.');
    }

    public function show($id)
    {
        request()->validate(['archive_status' => ['nullable', 'in:active,archived,all']]);
        $provider = $this->providerService->show($id, request('archive_status', 'active'));
        return $provider
            ? $this->success($provider, 200, 'Provider retrieved.')
            : $this->failure('Provider not found.', 404);
    }

    public function store(StoreProviderRequest $request)
    {
        $this->providerService->store($request);
        return $this->success(null, 201, 'Provider created.');
    }

    public function update(UpdateProviderRequest $request,$id)
    {
        return $this->providerService->update($request,$id)
            ? $this->success($this->providerService->show($id), 200, 'Provider updated.')
            : $this->failure('Provider not found.', 404);
    }

    public function destroy($id)
    {
        return $this->providerService->destroy($id)
            ? $this->success(null, 200, 'Provider deleted.')
            : $this->failure('Provider not found.', 404);
    }

    public function restore($id)
    {
        $result = $this->providerService->restore($id);
        return $result['status'] >= 400
            ? $this->failure($result['message'], $result['status'])
            : $this->success($result['data'], 200, $result['message']);
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
