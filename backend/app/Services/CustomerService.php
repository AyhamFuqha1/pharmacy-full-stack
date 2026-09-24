<?php

namespace App\Services;

use DB;

class CustomerService
{

    public function index($request = null)
    {
        $query = DB::table('customers');
        $this->applyArchiveStatus($query, $request?->input('archive_status', 'active'));
        if ($request?->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(fn ($builder) => $builder
                ->where('name', 'like', "%{$search}%")
                ->orWhere('phone_num', 'like', "%{$search}%"));
        }
        $query->when($request?->filled('name'), fn ($q) => $q->where('name', 'like', '%'.$request->name.'%'))
            ->when($request?->filled('phone'), fn ($q) => $q->where('phone_num', 'like', '%'.$request->phone.'%'))
            ->when($request?->filled('city'), fn ($q) => $q->where('city', 'like', '%'.$request->city.'%'))
            ->when($request?->filled('created_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->created_from))
            ->when($request?->filled('created_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->created_to));

        $sort = in_array($request?->input('sort'), ['id', 'name', 'created_at'], true) ? $request->sort : 'id';
        return $query->orderBy($sort, $request?->input('direction') === 'asc' ? 'asc' : 'desc')->paginate(min(max((int) ($request?->input('per_page', 15) ?? 15), 1), 100));
    }

    public function show($id, string $archiveStatus = 'active')
    {
        $customer = DB::table('customers')
            ->where('id', $id)
            ->when($archiveStatus === 'active', fn ($query) => $query->whereNull('deleted_at'))
            ->when($archiveStatus === 'archived', fn ($query) => $query->whereNotNull('deleted_at'))
            ->first();
        if ($customer) {
            $receivable=(float)app(AccountingReportService::class)->customerStatement((int)$customer->id)['balance'];
            $customer->current_receivable=round($receivable,2);
            $customer->available_credit=$customer->credit_limit===null?null:round((float)$customer->credit_limit-$receivable,2);
            $customer->exceeded=$customer->credit_limit!==null&&$receivable>(float)$customer->credit_limit;
            $customer->warning=$customer->credit_warning_threshold!==null&&$receivable>=(float)$customer->credit_warning_threshold;
        }
        return $customer;
    }

    public function store($customer)
    {
        return DB::transaction(function () use ($customer) {
            $customerId = DB::table('customers')->insertGetId([
                'name' => $customer['name'],
                'phone_num' => $customer['phone_num'],
                'city' => $customer['city'],
                'credit_limit' => $customer['credit_limit'] ?? null,
                'credit_warning_threshold' => $customer['credit_warning_threshold'] ?? null,
                'block_on_limit' => $customer['block_on_limit'] ?? false,
            ]);
            // Customer balances are derived from posted journal entries, never a legacy ledger row.
            return true;
        });

    }

    public function update($request, $id)
    {
        $customer = DB::table('customers')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$customer) {
            return false;
        }
        $data = $request->validated();

        DB::table('customers')
            ->where('id', $id)
            ->update($data);

        return true;
    }

    public function destory($id)
    {
        $customer = DB::table('customers')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$customer) {
            return false;
        }

        DB::table('customers')
            ->where('id', $id)
            ->update([
                "deleted_at" => now()
            ]);
        return true;
    }

    public function restore($id): array
    {
        $customer = DB::table('customers')->where('id', $id)->first();
        if (!$customer) return ['message' => 'Customer not found.', 'status' => 404];
        if ($customer->deleted_at === null) return ['message' => 'Customer is already active.', 'status' => 409];
        DB::table('customers')->where('id', $id)->update(['deleted_at' => null]);
        return ['message' => 'Customer restored.', 'status' => 200, 'data' => $this->show($id)];
    }

    private function applyArchiveStatus($query, string $status): void
    {
        if ($status === 'active') $query->whereNull('deleted_at');
        if ($status === 'archived') $query->whereNotNull('deleted_at');
    }

    public function incrementDept($id, $amount, $id_sales_invoices)
    {
        throw new \LogicException('Customer balances are posted only through sales invoices and receipts.');
    }
    public function decrementDept($id, $amount, $id_sales_invoices)
    {
        throw new \LogicException('Customer balances are posted only through sales invoices and receipts.');
    }


}
