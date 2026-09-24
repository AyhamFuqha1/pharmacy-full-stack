<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChequeService;
use Illuminate\Http\Request;

class ChequeController extends Controller
{
    private ChequeService $chequeService;

    public function __construct(ChequeService $chequeService)
    {
        $this->chequeService = $chequeService;
    }
    public function index(Request $request)
    {
        $request->validate([
            'cheque_number' => 'nullable|string|max:50', 'bank' => 'nullable|string|max:100',
            'direction' => 'nullable|in:incoming,outgoing', 'status' => 'nullable|in:pending,cleared,bounced',
            'customer_id' => 'nullable|integer|exists:customers,id', 'provider_id' => 'nullable|integer|exists:providers,id',
            'due_from' => 'nullable|date', 'due_to' => 'nullable|date|after_or_equal:due_from',
            'amount_min' => 'nullable|numeric|min:0', 'amount_max' => 'nullable|numeric|gte:amount_min',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return $this->collection($this->chequeService->index($request), 'Cheques retrieved.');
    }


    public function show($cheque)
    {
        $record = $this->chequeService->show($cheque);
        return $record
            ? $this->success($record, 200, 'Cheque retrieved.')
            : $this->failure('Cheque not found.', 404);
    }


    public function clear($id)
    {
        try {
            $response = $this->chequeService->clear($id, (int) auth()->id());
            return $response['status'] ? $this->success($this->chequeService->show($id), 200, $response['message']) : $this->failure($response['message'], 409);
        } catch (\Throwable $e) {
            report($e);
            return $this->failure('Server error.', 500);
        }
    }
    public function bounce($id)
    {
        try {
            $response = $this->chequeService->bounce($id, (int) auth()->id());
            return $response['status'] ? $this->success($this->chequeService->show($id), 200, $response['message']) : $this->failure($response['message'], 409);
        } catch (\Throwable $e) {
            report($e);
            return $this->failure('Server error.', 500);
        }
    }
}
