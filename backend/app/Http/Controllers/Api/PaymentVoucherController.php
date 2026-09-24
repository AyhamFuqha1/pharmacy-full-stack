<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentVoucherRequest;
use App\Services\PaymentVoucherService;
use Illuminate\Http\Request;
use Throwable;
use App\Exceptions\AccountingPeriodException;
use Illuminate\Validation\ValidationException;

class PaymentVoucherController extends Controller
{
    public function __construct(private PaymentVoucherService $payments) {}

    public function index(Request $request)
    {
        $request->validate(['id' => 'nullable|integer', 'provider_id' => 'nullable|integer', 'payment_method' => 'nullable|in:cash,cheque', 'amount_min' => 'nullable|numeric|min:0', 'amount_max' => 'nullable|numeric|gte:amount_min', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'status' => 'nullable|in:posted,cancelled']);
        $query = \DB::table('payment_vouchers as vouchers')->leftJoin('providers', 'providers.id', '=', 'vouchers.provider_id')->select('vouchers.*', 'providers.name as provider_name')
            ->when($request->filled('id'), fn ($q) => $q->where('vouchers.id', $request->id))->when($request->filled('provider_id'), fn ($q) => $q->where('vouchers.provider_id', $request->provider_id))
            ->when($request->filled('provider_search'), fn ($q) => $q->where('providers.name', 'like', '%'.$request->provider_search.'%'))->when($request->filled('payment_method'), fn ($q) => $q->where('vouchers.payment_method', $request->payment_method))
            ->when($request->filled('amount_min'), fn ($q) => $q->where('vouchers.amount', '>=', $request->amount_min))->when($request->filled('amount_max'), fn ($q) => $q->where('vouchers.amount', '<=', $request->amount_max))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('vouchers.date', '>=', $request->from))->when($request->filled('to'), fn ($q) => $q->whereDate('vouchers.date', '<=', $request->to))->when($request->filled('status'), fn ($q) => $q->where('vouchers.status', $request->status));
        return $this->collection($query->orderByDesc('vouchers.id')->paginate(min(max((int) $request->input('per_page', 15), 1), 100)), 'Payment vouchers retrieved.');
    }

    public function store(StorePaymentVoucherRequest $request)
    {
        $data = $request->validated();
        try {
            $id = $this->payments->store($data, $request->user());
            return $this->success($this->payments->show($id), 201, 'Payment voucher created.');
        } catch (Throwable $e) {
            if ($e instanceof AccountingPeriodException || $e instanceof ValidationException) throw $e;
            report($e); return $this->failure('Server error.', 500);
        }
    }

    public function show($id)
    {
        $payment = $this->payments->show($id);
        return $payment ? $this->success($payment, 200, 'Payment voucher retrieved.') : $this->failure('Payment voucher not found.', 404);
    }

    public function cancel(Request $request, $id)
    {
        try {
            if (!$this->payments->destroy($id, $request->user())) return $this->failure('Payment voucher not found or already cancelled.', 409);
            return $this->success($this->payments->show($id), 200, 'Payment voucher cancelled.');
        } catch (Throwable $e) {
            if ($e instanceof AccountingPeriodException) throw $e;
            report($e); return $this->failure('Server error.', 500);
        }
    }
}
