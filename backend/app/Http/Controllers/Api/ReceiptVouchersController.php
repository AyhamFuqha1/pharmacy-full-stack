<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReceiptVoucherRequest;
use App\Services\ReceiptVouchersServices;
use Illuminate\Http\Request;
use Throwable;
use App\Exceptions\AccountingPeriodException;
use Illuminate\Validation\ValidationException;

class ReceiptVouchersController extends Controller
{
    public function __construct(private ReceiptVouchersServices $receipts) {}

    public function index(Request $request)
    {
        $request->validate(['id' => 'nullable|integer', 'customer_id' => 'nullable|integer', 'payment_method' => 'nullable|in:cash,cheque', 'amount_min' => 'nullable|numeric|min:0', 'amount_max' => 'nullable|numeric|gte:amount_min', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'status' => 'nullable|in:posted,cancelled']);
        $query = \DB::table('receipt_vouchers as vouchers')->leftJoin('customers', 'customers.id', '=', 'vouchers.customer_id')->select('vouchers.*', 'customers.name as customer_name')
            ->when($request->filled('id'), fn ($q) => $q->where('vouchers.id', $request->id))->when($request->filled('customer_id'), fn ($q) => $q->where('vouchers.customer_id', $request->customer_id))
            ->when($request->filled('customer_search'), fn ($q) => $q->where('customers.name', 'like', '%'.$request->customer_search.'%'))->when($request->filled('payment_method'), fn ($q) => $q->where('vouchers.payment_method', $request->payment_method))
            ->when($request->filled('amount_min'), fn ($q) => $q->where('vouchers.amount', '>=', $request->amount_min))->when($request->filled('amount_max'), fn ($q) => $q->where('vouchers.amount', '<=', $request->amount_max))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('vouchers.date', '>=', $request->from))->when($request->filled('to'), fn ($q) => $q->whereDate('vouchers.date', '<=', $request->to))->when($request->filled('status'), fn ($q) => $q->where('vouchers.status', $request->status));
        return $this->collection($query->orderByDesc('vouchers.id')->paginate(min(max((int) $request->input('per_page', 15), 1), 100)), 'Receipt vouchers retrieved.');
    }

    public function store(StoreReceiptVoucherRequest $request)
    {
        $data = $request->validated();
        try {
            $id = $this->receipts->store($data, $request->user());
            return $this->success($this->receipts->show($id), 201, 'Receipt voucher created.');
        } catch (Throwable $e) {
            if ($e instanceof AccountingPeriodException || $e instanceof ValidationException) throw $e;
            report($e); return $this->failure('Server error.', 500);
        }
    }

    public function show($id)
    {
        $receipt = $this->receipts->show($id);
        return $receipt ? $this->success($receipt, 200, 'Receipt voucher retrieved.') : $this->failure('Receipt voucher not found.', 404);
    }

    public function cancel(Request $request, $id)
    {
        try {
            if (!$this->receipts->destroy($id, $request->user())) return $this->failure('Receipt voucher not found or already cancelled.', 409);
            return $this->success($this->receipts->show($id), 200, 'Receipt voucher cancelled.');
        } catch (Throwable $e) {
            if ($e instanceof AccountingPeriodException) throw $e;
            report($e); return $this->failure('Server error.', 500);
        }
    }
}
