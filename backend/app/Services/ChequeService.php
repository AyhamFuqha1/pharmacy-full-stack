<?php

namespace App\Services;

use DB;

class ChequeService
{
    /**
     * Create a new class instance.
     */
    public function index($request)
    {
        $paginator = $this->query()
            ->when($request->filled('cheque_number'), fn ($q) => $q->where('checks.check_number', 'like', '%'.$request->cheque_number.'%'))
            ->when($request->filled('bank'), fn ($q) => $q->where('checks.bank_name', 'like', '%'.$request->bank.'%'))
            ->when($request->filled('direction'), fn ($q) => $q->where('checks.direction', $request->direction === 'incoming' ? 'in' : 'out'))
            ->when($request->filled('status'), fn ($q) => $q->where('checks.status', $request->status))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('checks.customer_id', $request->customer_id))
            ->when($request->filled('provider_id'), fn ($q) => $q->where('checks.provider_id', $request->provider_id))
            ->when($request->filled('due_from'), fn ($q) => $q->whereDate('checks.due_date', '>=', $request->due_from))
            ->when($request->filled('due_to'), fn ($q) => $q->whereDate('checks.due_date', '<=', $request->due_to))
            ->when($request->filled('amount_min'), fn ($q) => $q->where('checks.amount', '>=', $request->amount_min))
            ->when($request->filled('amount_max'), fn ($q) => $q->where('checks.amount', '<=', $request->amount_max))
            ->orderByDesc('checks.id')->paginate(min(max((int) $request->input('per_page', 15), 1), 100));
        $paginator->through(fn ($cheque) => $this->present($cheque));
        return $paginator;
    }

    public function createLinked(array $data): int
    {
        return DB::table('checks')->insertGetId([
            'check_number' => $data['cheque_number'], 'bank_name' => $data['bank'], 'amount' => $data['amount'],
            'due_date' => $data['due_date'], 'status' => 'pending', 'direction' => $data['direction'] === 'incoming' ? 'in' : 'out',
            'reference_type' => isset($data['sales_invoice_id']) ? 'sales_invoice' : ($data['direction'] === 'incoming' ? 'receipt_voucher' : 'payment_voucher'),
            'reference_id' => $data['sales_invoice_id'] ?? $data['receipt_voucher_id'] ?? $data['payment_voucher_id'],
            'customer_id' => $data['customer_id'] ?? null, 'provider_id' => $data['provider_id'] ?? null,
            'receipt_voucher_id' => $data['receipt_voucher_id'] ?? null, 'payment_voucher_id' => $data['payment_voucher_id'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function store($request, $referenceType, $referenceId, $direction)
    {
        DB::table('checks')->insertGetId([
            "check_number" => $request->check_number,
            "bank_name" => $request->bank_name,
            "amount" => $request->amount,
            "due_date" => $request->due_date,
            "status" => 'pending',
            "direction" => $direction,
            "reference_type" => $referenceType,
            "reference_id" => $referenceId,
            "created_at" => now(),
        ]);
        return DB::getPdo()->lastInsertId();
    }


    public function show($cheque)
    {
        $record = $this->query()->where('checks.id', $cheque)->first();
        return $record ? $this->present($record) : null;
    }
    public function clear($id, int $userId)
    {
        return $this->transition($id, 'cleared', $userId);
    }

    public function bounce($id, int $userId)
    {
        return $this->transition($id, 'bounced', $userId);
    }

    private function transition($id, string $status, int $userId): array
    {
        return DB::transaction(function () use ($id, $status, $userId) {
        $check = DB::table('checks')->where('id', $id)->lockForUpdate()->first();
        if (!$check) {
            return [
                "status" => false,
                "message" => __('api.cheque_not_found')
            ];
        }
        if ($check->status !== "pending") {
            return [
                "status" => false,
                "message" => __('api.cheque_already_processed')
            ];
        }
        app(AccountingPostingService::class)->postChequeTransition($check, $status === 'cleared' ? 'clear' : 'bounce', $userId);
        if ($status === 'bounced') {
            if ($check->receipt_voucher_id) app(SettlementAllocationService::class)->remove('receipt', (int)$check->receipt_voucher_id);
            if ($check->payment_voucher_id) app(SettlementAllocationService::class)->remove('payment', (int)$check->payment_voucher_id);
        }
        DB::table('checks')->where('id', $id)->update(['status' => $status, $status === 'cleared' ? 'cleared_at' : 'bounced_at' => now(), 'updated_at' => now()]);
        return [
            "status" => true,
            "message" => __('api.cheque_transition_success', ['status' => $status])
        ];
        });
    }

    private function query()
    {
        return DB::table('checks')
            ->leftJoin('customers', 'customers.id', '=', 'checks.customer_id')
            ->leftJoin('providers', 'providers.id', '=', 'checks.provider_id')
            ->select('checks.*', 'customers.name as customer_name', 'providers.name as provider_name')
            ->selectRaw("CASE WHEN checks.direction = 'in' THEN 'incoming' ELSE 'outgoing' END as direction_label");
    }

    private function present(object $cheque): object
    {
        $cheque->direction = $cheque->direction_label;
        $cheque->cheque_number = $cheque->check_number;
        $cheque->bank = $cheque->bank_name;
        $cheque->customer = $cheque->customer_id ? (object) ['id' => $cheque->customer_id, 'name' => $cheque->customer_name] : null;
        $cheque->provider = $cheque->provider_id ? (object) ['id' => $cheque->provider_id, 'name' => $cheque->provider_name] : null;
        unset($cheque->direction_label, $cheque->customer_name, $cheque->provider_name);
        return $cheque;
    }
}
