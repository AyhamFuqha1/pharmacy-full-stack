<?php

namespace App\Services;

use App\Models\ProductBatch;
use App\Models\Stock;
use DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PurchaseInvoiceService
{
    public function index()
    {
        return DB::table('purchase_invoices')->get();
    }

    public function show($id)
    {
        return $this->document((int) $id);
    }

    public function store($request, $user)
    {
        return DB::transaction(function () use ($request, $user) {
            $branchId = app(BatchBranchService::class)->resolve($request->branch_id ?? null, $user->branch_id ? (int) $user->branch_id : null);
            $id_purchase_invoices = DB::table('purchase_invoices')->insertGetId([
                'document_number' => app(DocumentNumberService::class)->next('purchase','PUR'),
                'provider_id' => $request->provider_id,
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'amount' => 0,
                'descount' => 0,
                'invoice_discount' => $request->invoice_discount ?? 0,
                'date' => $request->date,
                'due_date' => $request->due_date,
                'status' => 'draft',
            ]);

            $total_before_discount = 0;
            $items = [];

            foreach ($request->items as $item) {
                $snapshot = $this->purchaseUnitSnapshot($item);
                $lineTotal = ($item['purchase_price'] * $item['amount']) - ($item['item_discount'] ?? 0);
                $total_before_discount += $lineTotal;

                $items[] = [
                    'purchase_invoice_id' => $id_purchase_invoices,
                    'product_id' => $item['product_id'],
                    'batch_number' => $item['batch_number'],
                    'amount' => $snapshot['base_quantity'],
                    'transaction_unit_id' => $snapshot['transaction_unit_id'],
                    'transaction_unit_name' => $snapshot['transaction_unit_name'],
                    'transaction_quantity' => $snapshot['transaction_quantity'],
                    'transaction_unit_cost' => $snapshot['transaction_unit_cost'],
                    'conversion_factor' => $snapshot['conversion_factor'],
                    'base_quantity' => $snapshot['base_quantity'],
                    'expire_date' => $item['expire_date'] ?: null,
                    'purchase_price' => $snapshot['base_unit_cost'],
                    'selling_price' => $this->compatibilitySalePrice((int) $item['product_id']),
                    'discount' => $item['item_discount'] ?? 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $total_after_discount = $total_before_discount - ($request->invoice_discount ?? 0);

            DB::table('prod_purch')->insert($items);
            DB::table('purchase_invoices')->where('id', $id_purchase_invoices)->update([
                'total_before_discount' => $total_before_discount,
                'total_after_discount' => $total_after_discount,
                'amount' => $total_after_discount,
                'descount' => $request->invoice_discount ?? 0,
            ]);

            return $this->document($id_purchase_invoices);
        });
    }

    public function post($id, $user)
    {
        return DB::transaction(function () use ($id, $user) {
            $purchase = DB::table('purchase_invoices')->where('id', $id)->lockForUpdate()->first();
            if (!$purchase || in_array($purchase->status, ['posted', 'cancelled'], true)) {
                return false;
            }

            $products = DB::table('prod_purch')->where('purchase_invoice_id', $id)->get();
            $branchId = app(BatchBranchService::class)->resolve($purchase->branch_id ? (int) $purchase->branch_id : null, $user->branch_id ? (int) $user->branch_id : null);
            if ($purchase->branch_id === null && $branchId !== null) {
                DB::table('purchase_invoices')->where('id', $id)->update(['branch_id' => $branchId]);
            }

            foreach ($products as $product) {
                $batch = ProductBatch::query()
                    ->where('product_id', $product->product_id)
                    ->where('branch_id', $branchId)
                    ->where('batch_number', $product->batch_number)
                    ->when(
                        $product->expire_date,
                        fn ($query, $expiry) => $query->whereDate('expiry_date', $expiry),
                        fn ($query) => $query->whereNull('expiry_date'),
                    )
                    ->lockForUpdate()
                    ->first();

                if (!$batch) {
                    $batch = ProductBatch::query()->create([
                        'product_id' => $product->product_id,
                        'branch_id' => $branchId,
                        'batch_number' => $product->batch_number,
                        'expiry_date' => $product->expire_date,
                        'quantity' => 0,
                        'purchase_price' => $product->purchase_price,
                        'sale_price' => $product->selling_price,
                    ]);
                }

                $batch->applyPurchase((int) ($product->base_quantity ?? $product->amount), (float) $product->purchase_price, $id, $user->id, $branchId);
                $batch->refresh();

                DB::table('prod_purch')->where('id', $product->id)->update([
                    'batch_id' => $batch->id,
                    'updated_at' => now(),
                ]);
            }

            DB::table('purchase_invoices')->where('id', $id)->update(['status' => 'posted']);
            app(AccountingPostingService::class)->postPurchaseInvoice($id);
            app(PrintIdentityService::class)->capture('purchase_invoice', $id);

            return $this->document($id);
        });
    }

    public function cancel($id, $user)
    {
        $purchase = DB::table('purchase_invoices')->where('id', $id)->first();
        if (!$purchase || $purchase->status !== 'draft') {
            return false;
        }

        DB::table('purchase_invoices')->where('id', $id)->update(['status' => 'cancelled']);
        return $this->document($id);
    }

    public function update($request, $id, $user): mixed
    {
        return DB::transaction(function () use ($request, $id, $user) {
            $purch = DB::table('purchase_invoices')->where('id', $id)->first();
            if (!$purch || $purch->status !== 'draft') {
                return false;
            }

            $prod = DB::table('prod_purch')->latest('id')->value('id');

            DB::table('purchase_invoices')->where('id', $id)->update([
                'provider_id' => $request->provider_id,
                'updated_by' => $user->id,
                'date' => $request->date ?? $purch->date,
                'due_date' => $request->due_date,
                'invoice_discount' => $request->invoice_discount ?? 0,
            ]);

            $items = [];
            $lastId = $prod;
            $total_before_discount = 0;

            foreach ($request->items as $item) {
                $snapshot = $this->purchaseUnitSnapshot($item);
                $existing = !empty($item['id']) ? DB::table('prod_purch')->where('id', $item['id'])->first() : null;
                $items[] = [
                    'id' => $item['id'] ?? ++$lastId,
                    'purchase_invoice_id' => $id,
                    'product_id' => $item['product_id'],
                    'batch_number' => $item['batch_number'],
                    'amount' => $snapshot['base_quantity'],
                    'transaction_unit_id' => $snapshot['transaction_unit_id'],
                    'transaction_unit_name' => $snapshot['transaction_unit_name'],
                    'transaction_quantity' => $snapshot['transaction_quantity'],
                    'transaction_unit_cost' => $snapshot['transaction_unit_cost'],
                    'conversion_factor' => $snapshot['conversion_factor'],
                    'base_quantity' => $snapshot['base_quantity'],
                    'expire_date' => $item['expire_date'] ?: null,
                    'purchase_price' => $snapshot['base_unit_cost'],
                    'selling_price' => $this->compatibilitySalePrice((int) $item['product_id'], $existing?->selling_price),
                    'discount' => $item['item_discount'] ?? 0,
                ];
                $total_before_discount += ($item['purchase_price'] * $item['amount']) - ($item['item_discount'] ?? 0);
            }

            $total_after_discount = $total_before_discount - ($request->invoice_discount ?? 0);

            DB::table('purchase_invoices')->where('id', $id)->update([
                'total_before_discount' => $total_before_discount,
                'total_after_discount' => $total_after_discount,
                // Kept synchronized while the accounting poster retains its
                // compatibility fallback to the legacy columns.
                'amount' => $total_after_discount,
                'descount' => $request->invoice_discount ?? 0,
            ]);

            DB::table('prod_purch')->upsert(
                $items,
                ['id'],
                ['amount', 'transaction_unit_id', 'transaction_unit_name', 'transaction_quantity', 'transaction_unit_cost', 'conversion_factor', 'base_quantity', 'expire_date', 'purchase_price', 'selling_price', 'discount', 'batch_number', 'product_id']
            );

            $ids = collect($items)->pluck('id')->filter()->toArray();
            DB::table('prod_purch')->where('purchase_invoice_id', $id)->whereNotIn('id', $ids)->delete();

            return $this->document($id);
        });
    }

    public function document(int $id): ?object
    {
        $invoice = DB::table('purchase_invoices')->where('id', $id)->first();
        if ($invoice) {
            $invoice->provider = DB::table('providers')
                ->where('id', $invoice->provider_id)
                ->first(['id', 'name']);
            $returned = DB::table('purch_returns')->where('invoice_id', $id)->whereNotNull('invoice_item_id')
                ->groupBy('invoice_item_id')->selectRaw('invoice_item_id, SUM(quantity) as returned_quantity')->pluck('returned_quantity', 'invoice_item_id');
            $invoice->items = DB::table('prod_purch')->where('purchase_invoice_id', $id)->get()->map(function ($line) use ($returned) {
                $line->original_quantity = (int) $line->amount;
                $line->returned_quantity = (int) ($returned[$line->id] ?? 0);
                $line->available_return_quantity = max(0, $line->original_quantity - $line->returned_quantity);
                $factor = (float) ($line->conversion_factor ?: 1);
                $line->original_transaction_quantity = $line->transaction_quantity === null ? null : (float) $line->transaction_quantity;
                $line->returned_transaction_quantity = $line->transaction_quantity === null ? null : $line->returned_quantity / $factor;
                $line->available_return_transaction_quantity = $line->transaction_quantity === null ? null : $line->available_return_quantity / $factor;
                return $line;
            });
        }
        return $invoice;
    }

    private function purchaseUnitSnapshot(array $item): array
    {
        $quantity = (float) $item['amount'];
        $unitCost = (float) $item['purchase_price'];
        $unitId = !empty($item['unit_id']) ? (int) $item['unit_id'] : null;
        $unit = $unitId ? DB::table('product_units')
            ->join('units', 'units.id', '=', 'product_units.unit_id')
            ->where([
                'product_units.product_id' => $item['product_id'],
                'product_units.unit_id' => $unitId,
                'product_units.is_active' => true,
            ])
            ->where(fn ($query) => $query->where('product_units.is_purchase_unit', true)->orWhere('product_units.is_base', true))
            ->select('product_units.base_quantity', 'product_units.display_name', 'units.name')
            ->first() : null;

        if ($unitId && !$unit) {
            throw ValidationException::withMessages(['unit_id' => ['Selected unit is not an active purchase unit for this product.']]);
        }

        $factor = (float) ($unit->base_quantity ?? 1);
        if ($factor <= 0) {
            throw ValidationException::withMessages(['unit_id' => ['Invalid purchase unit conversion.']]);
        }

        return [
            'transaction_unit_id' => $unitId,
            'transaction_unit_name' => $unit ? ($unit->display_name ?: $unit->name) : null,
            'transaction_quantity' => $quantity,
            'transaction_unit_cost' => round($unitCost, 4),
            'conversion_factor' => $factor,
            'base_quantity' => round($quantity * $factor, 3),
            'base_unit_cost' => round($unitCost / $factor, 2),
        ];
    }

    private function compatibilitySalePrice(int $productId, mixed $existing = null): float
    {
        if ($existing !== null) return (float) $existing;
        return (float) (DB::table('product_units')
            ->where(['product_id' => $productId, 'is_base' => true, 'is_active' => true])
            ->value('sale_price') ?? 0);
    }
}
