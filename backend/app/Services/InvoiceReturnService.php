<?php

namespace App\Services;

use App\Models\ProductBatch;
use App\Models\Stock;
use DB;
use Exception;

class InvoiceReturnService
{
    public function salesIndex($request)
    {
        $grouped = $this->groupedSalesReturnListQuery($request);
        $legacy = $this->legacySalesReturnListQuery($request);
        return DB::query()->fromSub($grouped->unionAll($legacy), 'return_documents')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(min(max((int) $request->input('per_page', 15), 1), 100));
    }

    public function salesShow(int $id): ?object
    {
        $header = DB::table('sales_returns')->where('id', $id)->first();
        if ($header) return $this->groupedSalesReturnDocument($header);
        $legacy = $this->salesHistoryQuery()->whereNull('returns.sales_return_id')->where('returns.id', $id)->first();
        return $legacy;
    }

    private function groupedSalesReturnListQuery($request)
    {
        return DB::table('sales_returns as documents')
            ->join('sales_invoices as invoices','invoices.id','=','documents.invoice_id')
            ->join('invoice_returns as lines','lines.sales_return_id','=','documents.id')
            ->join('products','products.id','=','lines.product_id')->join('product_batches as batches','batches.id','=','lines.batch_id')
            ->leftJoin('customers','customers.id','=','documents.customer_id')->leftJoin('users','users.id','=','documents.created_by')
            ->when($request->filled('return_id'),fn($q)=>$q->where('documents.id',$request->return_id))
            ->when($request->filled('return_number'),fn($q)=>$q->where('documents.document_number','like','%'.$request->return_number.'%'))
            ->when($request->filled('source_invoice_id'),fn($q)=>$q->where('documents.invoice_id',$request->source_invoice_id))
            ->when($request->filled('source_document_number'),fn($q)=>$q->where('invoices.document_number','like','%'.$request->source_document_number.'%'))
            ->when($request->filled('customer_id'),fn($q)=>$q->where('documents.customer_id',$request->customer_id))
            ->when($request->filled('customer_search'),fn($q)=>$q->where('customers.name','like','%'.$request->customer_search.'%'))
            ->when($request->filled('product_id'),fn($q)=>$q->where('lines.product_id',$request->product_id))
            ->when($request->filled('product_search'),fn($q)=>$q->where(fn($s)=>$s->where('products.name','like','%'.$request->product_search.'%')->orWhere('products.barcode','like','%'.$request->product_search.'%')))
            ->when($request->filled('batch_id'),fn($q)=>$q->where('lines.batch_id',$request->batch_id))
            ->when($request->filled('batch_search'),fn($q)=>$q->where('batches.batch_number','like','%'.$request->batch_search.'%'))
            ->when($request->filled('from'),fn($q)=>$q->whereDate('documents.return_date','>=',$request->from))->when($request->filled('to'),fn($q)=>$q->whereDate('documents.return_date','<=',$request->to))
            ->when($request->filled('user_id'),fn($q)=>$q->where('documents.created_by',$request->user_id))
            ->groupBy('documents.id','documents.document_number','documents.invoice_id','invoices.document_number','documents.created_at','documents.updated_at','documents.gross_original_total','documents.customer_credit_total','documents.deduction_total','documents.customer_id','customers.name','documents.created_by','users.name')
            ->selectRaw('documents.id, documents.document_number, documents.invoice_id as source_invoice_id, invoices.document_number as source_document_number, documents.created_at, documents.updated_at, SUM(lines.quantity) as quantity, documents.gross_original_total as gross_original_return_value, documents.customer_credit_total as customer_credit_value, documents.deduction_total as return_deduction, documents.customer_credit_total as total_returned_value, COUNT(lines.id) as item_count, documents.customer_id, customers.name as customer_name, documents.created_by as user_id, users.name as user_name, NULL as product_id, NULL as product_name, NULL as product_barcode, NULL as batch_id, NULL as batch_number, NULL as expiry_date, NULL as price, NULL as original_returnable_unit_amount, NULL as customer_credit_unit_amount, NULL as return_to_inventory, NULL as adjustment_reason, NULL as reason, NULL as original_line_id');
    }

    private function legacySalesReturnListQuery($request)
    {
        return DB::table('invoice_returns as returns')
            ->join('sales_invoices as invoices','invoices.id','=','returns.invoice_id')
            ->join('products','products.id','=','returns.product_id')->join('product_batches as batches','batches.id','=','returns.batch_id')
            ->leftJoin('customers','customers.id','=','invoices.customer_id')->leftJoin('users','users.id','=','invoices.user_id')
            ->leftJoin('prod_sales as original_lines',fn($join)=>$join->on('original_lines.sales_invoice_id','=','returns.invoice_id')->on('original_lines.batch_id','=','returns.batch_id'))
            ->whereNull('returns.sales_return_id')
            ->when($request->filled('return_id'),fn($q)=>$q->where('returns.id',$request->return_id))
            ->when($request->filled('return_number'),fn($q)=>$q->where('returns.document_number','like','%'.$request->return_number.'%'))
            ->when($request->filled('source_invoice_id'),fn($q)=>$q->where('returns.invoice_id',$request->source_invoice_id))
            ->when($request->filled('source_document_number'),fn($q)=>$q->where('invoices.document_number','like','%'.$request->source_document_number.'%'))
            ->when($request->filled('customer_id'),fn($q)=>$q->where('invoices.customer_id',$request->customer_id))
            ->when($request->filled('customer_search'),fn($q)=>$q->where('customers.name','like','%'.$request->customer_search.'%'))
            ->when($request->filled('product_id'),fn($q)=>$q->where('returns.product_id',$request->product_id))
            ->when($request->filled('product_search'),fn($q)=>$q->where(fn($s)=>$s->where('products.name','like','%'.$request->product_search.'%')->orWhere('products.barcode','like','%'.$request->product_search.'%')))
            ->when($request->filled('batch_id'),fn($q)=>$q->where('returns.batch_id',$request->batch_id))
            ->when($request->filled('batch_search'),fn($q)=>$q->where('batches.batch_number','like','%'.$request->batch_search.'%'))
            ->when($request->filled('from'),fn($q)=>$q->whereDate('returns.created_at','>=',$request->from))->when($request->filled('to'),fn($q)=>$q->whereDate('returns.created_at','<=',$request->to))
            ->when($request->filled('user_id'),fn($q)=>$q->where(fn($u)=>$u->where('returns.created_by',$request->user_id)->orWhere(fn($legacy)=>$legacy->whereNull('returns.created_by')->where('invoices.user_id',$request->user_id))))
            ->when($request->filled('reason'),fn($q)=>$q->where('returns.reason','like','%'.$request->reason.'%'))
            ->selectRaw('returns.id, returns.document_number, returns.invoice_id as source_invoice_id, invoices.document_number as source_document_number, returns.created_at, returns.updated_at, returns.quantity, returns.quantity * COALESCE(returns.original_returnable_unit_amount, returns.price) as gross_original_return_value, returns.quantity * COALESCE(returns.customer_credit_unit_amount, returns.price) as customer_credit_value, returns.quantity * (COALESCE(returns.original_returnable_unit_amount, returns.price) - COALESCE(returns.customer_credit_unit_amount, returns.price)) as return_deduction, returns.quantity * COALESCE(returns.customer_credit_unit_amount, returns.price) as total_returned_value, 1 as item_count, invoices.customer_id, customers.name as customer_name, COALESCE(returns.created_by, invoices.user_id) as user_id, users.name as user_name, returns.product_id, products.name as product_name, products.barcode as product_barcode, returns.batch_id, batches.batch_number, batches.expiry_date, returns.price, returns.original_returnable_unit_amount, returns.customer_credit_unit_amount, returns.return_to_inventory, returns.adjustment_reason, returns.reason, COALESCE(returns.original_sale_line_id, original_lines.id) as original_line_id');
    }

    private function groupedSalesReturnDocument(object $header): object
    {
        $invoice = DB::table('sales_invoices')->where('id',$header->invoice_id)->first();
        $customer = $header->customer_id ? DB::table('customers')->where('id',$header->customer_id)->first(['id','name']) : null;
        $branch = $header->branch_id ? DB::table('branches')->where('id',$header->branch_id)->first(['id','name','code']) : null;
        $creator = DB::table('users')->where('id',$header->created_by)->first(['id','name']);
        $lines = $this->salesHistoryQuery()->where('returns.sales_return_id',$header->id)->get();
        return (object)[
            'id'=>$header->id,'document_number'=>$header->document_number,'source_invoice_id'=>$header->invoice_id,
            'source_document_number'=>$invoice->document_number??null,'created_at'=>$header->created_at,'return_date'=>$header->return_date,
            'customer'=>$customer,'branch'=>$branch,'user_id'=>$header->created_by,'user_name'=>$creator->name??null,
            'item_count'=>$lines->count(),'total_quantity'=>(float)$lines->sum('quantity'),'lines'=>$lines,
            'totals'=>(object)['gross_original_return_total'=>(float)$header->gross_original_total,'customer_credit_total'=>(float)$header->customer_credit_total,'return_deduction_total'=>(float)$header->deduction_total],
        ];
    }


    public function purchaseIndex($request)
    {
        return $this->purchaseHistoryQuery()
            ->when($request->filled('return_id'), fn ($q) => $q->where('returns.id', $request->return_id))
            ->when($request->filled('return_number'), fn ($q) => $q->where('returns.document_number', 'like', '%'.$request->return_number.'%'))
            ->when($request->filled('source_invoice_id'), fn ($q) => $q->where('returns.invoice_id', $request->source_invoice_id))
            ->when($request->filled('source_document_number'), fn ($q) => $q->where('invoices.document_number', 'like', '%'.$request->source_document_number.'%'))
            ->when($request->filled('provider_id'), fn ($q) => $q->where('invoices.provider_id', $request->provider_id))
            ->when($request->filled('provider_search'), fn ($q) => $q->where('providers.name', 'like', '%'.$request->provider_search.'%'))
            ->when($request->filled('product_id'), fn ($q) => $q->where('products.id', $request->product_id))
            ->when($request->filled('product_search'), fn ($q) => $q->where(fn ($p) => $p->where('products.name', 'like', '%'.$request->product_search.'%')->orWhere('products.barcode', 'like', '%'.$request->product_search.'%')))
            ->when($request->filled('batch_id'), fn ($q) => $q->where('returns.batch_id', $request->batch_id))->when($request->filled('batch_search'), fn ($q) => $q->where('batches.batch_number', 'like', '%'.$request->batch_search.'%'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('returns.created_at', '>=', $request->from))->when($request->filled('to'), fn ($q) => $q->whereDate('returns.created_at', '<=', $request->to))
            ->when($request->filled('user_id'), fn ($q) => $q->where('returns.created_by', $request->user_id))->when($request->filled('reason'), fn ($q) => $q->where('returns.reason', 'like', '%'.$request->reason.'%'))
            ->orderByDesc('returns.id')
            ->paginate(min(max((int) $request->input('per_page', 15), 1), 100));
    }

    public function purchaseShow(int $id): ?object
    {
        return $this->purchaseHistoryQuery()->where('returns.id', $id)->first();
    }

    public function store($request, $user)
    {
        if (count($request->items) > 1) return $this->storeGroupedSalesReturn($request->items, $user);
        return DB::transaction(function () use ($request, $user) {
            $invoiceId = null;
            $totalReturn = 0;
            $returns = [];

            foreach ($request->items as $item) {
                $invoiceId = $item['invoice_id'];
                $saleLines = DB::table('prod_sales')->where('sales_invoice_id', $item['invoice_id'])->where('batch_id', $item['batch_id'])->lockForUpdate()->get();

                if ($saleLines->isEmpty()) {
                    throw new \DomainException('This batch does not belong to the sales invoice.');
                }
                if ($saleLines->count() !== 1) {
                    throw new \DomainException('This return cannot be matched safely because the invoice contains multiple lines for the same batch.');
                }
                $saleLine = $saleLines->first();

                $alreadyReturned = (int) DB::table('invoice_returns')->where('invoice_id', $item['invoice_id'])->where('batch_id', $item['batch_id'])->sum('quantity');
                $requestedForBatch = (int) collect($request->items)->where('invoice_id', $item['invoice_id'])->where('batch_id', $item['batch_id'])->sum('quantity');
                if ($alreadyReturned + $requestedForBatch > (int) $saleLine->amount) {
                    throw new \DomainException('Returned quantity exceeds sold quantity.');
                }

                $batch = ProductBatch::query()->whereKey($item['batch_id'])->lockForUpdate()->first();
                if (!$batch || $batch->deleted_at !== null) {
                    throw new \DomainException('Batch not available for return.');
                }

                $returnToInventory = filter_var($item['return_to_inventory'] ?? true, FILTER_VALIDATE_BOOL);
                if ($returnToInventory) {
                    $batch->quantity += (int) $item['quantity'];
                    $batch->save();
                }

                $soldQuantity = max((int) $saleLine->amount, 1);
                $originalUnitMinor = intdiv($this->moneyToMinor($saleLine->final_total) + intdiv($soldQuantity, 2), $soldQuantity);
                $creditUnitMinor = $this->moneyToMinor($item['customer_credit_unit_amount'] ?? ($originalUnitMinor / 100));
                $persistedUnitPrice = number_format($originalUnitMinor / 100, 2, '.', '');
                $creditUnitPrice = number_format($creditUnitMinor / 100, 2, '.', '');
                $returns[] = [
                    'invoice_id' => $item['invoice_id'],
                    'product_id' => $item['product_id'],
                    'batch_id' => $item['batch_id'],
                    'quantity' => $item['quantity'],
                    'price' => $persistedUnitPrice,
                    'original_returnable_unit_amount' => $persistedUnitPrice,
                    'customer_credit_unit_amount' => $creditUnitPrice,
                    'return_to_inventory' => $returnToInventory,
                    'reason' => $item['reason'] ?? null,
                    'adjustment_reason' => $item['reason'] ?? null,
                    'created_by' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $totalReturn += ((int) $item['quantity'] * $creditUnitMinor) / 100;
            }

            foreach ($returns as &$returnRow) $returnRow['document_number'] = app(DocumentNumberService::class)->next('sales_return','SRET');
            unset($returnRow);
            foreach ($returns as $returnRow) {
                $returnId = DB::table('invoice_returns')->insertGetId($returnRow);
                if ($returnRow['return_to_inventory']) {
                    Stock::query()->create([
                        'batch_id' => $returnRow['batch_id'], 'quantity' => (int) $returnRow['quantity'], 'type' => 'SALES_RETURN_IN',
                        'reference_type' => 'sales_return', 'reference_id' => $returnId, 'user_id' => $user->id, 'created_at' => now(),
                    ]);
                }
                app(AccountingPostingService::class)->postSalesReturn($returnId);
            }
            app(PrintIdentityService::class)->capture('sales_return', $returnId);

            return [
                'invoice_id' => $invoiceId,
                'items_count' => count($returns),
                'total_return' => $totalReturn,
            ];
        });
    }

    private function storeGroupedSalesReturn(array $items, $user): array
    {
        return DB::transaction(function () use ($items, $user) {
            $invoiceId = (int) $items[0]['invoice_id'];
            if (collect($items)->pluck('invoice_id')->map(fn ($id) => (int) $id)->unique()->count() !== 1) {
                throw new \DomainException('All return lines must belong to the same sales invoice.');
            }
            $invoice = DB::table('sales_invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            if (! $invoice) throw new \DomainException('Sales invoice not found.');

            $prepared = [];
            foreach ($items as $item) {
                $saleLines = DB::table('prod_sales')->where('sales_invoice_id', $invoiceId)->where('batch_id', $item['batch_id'])->lockForUpdate()->get();
                if ($saleLines->isEmpty()) throw new \DomainException('This batch does not belong to the sales invoice.');
                if ($saleLines->count() !== 1) throw new \DomainException('This return cannot be matched safely because the invoice contains multiple lines for the same batch.');
                $saleLine = $saleLines->first();
                $alreadyReturned = (int) DB::table('invoice_returns')->where('invoice_id', $invoiceId)->where('batch_id', $item['batch_id'])->sum('quantity');
                $requested = (int) collect($items)->where('batch_id', $item['batch_id'])->sum('quantity');
                if ($alreadyReturned + $requested > (int) $saleLine->amount) throw new \DomainException('Returned quantity exceeds sold quantity.');
                $batch = ProductBatch::query()->whereKey($item['batch_id'])->lockForUpdate()->first();
                if (! $batch || $batch->deleted_at !== null) throw new \DomainException('Batch not available for return.');
                $soldQuantity = max((int) $saleLine->amount, 1);
                $originalMinor = intdiv($this->moneyToMinor($saleLine->final_total) + intdiv($soldQuantity, 2), $soldQuantity);
                $creditMinor = $this->moneyToMinor($item['customer_credit_unit_amount'] ?? ($originalMinor / 100));
                $prepared[] = compact('item', 'saleLine', 'batch', 'originalMinor', 'creditMinor');
            }

            $documentNumber = app(DocumentNumberService::class)->next('sales_return', 'SRET');
            $sequence = (int) substr($documentNumber, -6);
            $documentId = ((int) now()->format('Y') * 1000000) + $sequence;
            $gross = collect($prepared)->sum(fn ($x) => (int) $x['item']['quantity'] * $x['originalMinor']) / 100;
            $credit = collect($prepared)->sum(fn ($x) => (int) $x['item']['quantity'] * $x['creditMinor']) / 100;
            DB::table('sales_returns')->insert([
                'id' => $documentId, 'document_number' => $documentNumber, 'invoice_id' => $invoiceId,
                'customer_id' => $invoice->customer_id, 'branch_id' => $invoice->branch_id,
                'return_date' => now()->toDateString(), 'created_by' => $user->id, 'status' => 'posted',
                'gross_original_total' => $gross, 'customer_credit_total' => $credit,
                'deduction_total' => $gross - $credit, 'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($prepared as $x) {
                $item = $x['item']; $batch = $x['batch']; $restock = filter_var($item['return_to_inventory'] ?? true, FILTER_VALIDATE_BOOL);
                if ($restock) { $batch->quantity += (int) $item['quantity']; $batch->save(); }
                $lineId = DB::table('invoice_returns')->insertGetId([
                    'sales_return_id' => $documentId, 'original_sale_line_id' => $x['saleLine']->id,
                    'invoice_id' => $invoiceId, 'product_id' => $item['product_id'], 'batch_id' => $item['batch_id'],
                    'quantity' => $item['quantity'], 'price' => number_format($x['originalMinor'] / 100, 2, '.', ''),
                    'original_returnable_unit_amount' => number_format($x['originalMinor'] / 100, 2, '.', ''),
                    'customer_credit_unit_amount' => number_format($x['creditMinor'] / 100, 2, '.', ''),
                    'return_to_inventory' => $restock, 'reason' => $item['reason'] ?? null,
                    'adjustment_reason' => $item['reason'] ?? null, 'created_by' => $user->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($restock) Stock::query()->create([
                    'batch_id' => $item['batch_id'], 'quantity' => (int) $item['quantity'], 'type' => 'SALES_RETURN_IN',
                    'reference_type' => 'sales_return', 'reference_id' => $documentId, 'user_id' => $user->id, 'created_at' => now(),
                ]);
            }
            app(AccountingPostingService::class)->postGroupedSalesReturn($documentId);
            app(PrintIdentityService::class)->capture('sales_return', $documentId);
            return ['id' => $documentId, 'document_number' => $documentNumber, 'invoice_id' => $invoiceId,
                'items_count' => count($prepared), 'total_return' => $credit];
        });
    }

    public function purchase($request, $user, $id)
    {
        return DB::transaction(function () use ($request, $user, $id) {
            $invoices = DB::table('purchase_invoices')->where('id', $id)->first();
            if (!$invoices || $invoices->status !== 'posted') {
                return [
                    'status' => false,
                    'message' => 'Invoice not found or not posted',
                ];
            }

            $items = [];
            $total_price = 0;

            foreach ($request->items as $item) {
                $batch = ProductBatch::query()->whereKey($item['batch_id'])->lockForUpdate()->first();
                if (!$batch) {
                    throw new \DomainException('Batch not found.');
                }

                if ($item['quantity'] > (int) $batch->quantity) {
                    throw new \DomainException('Not enough stock in batch to process purchase return.');
                }

                $batch->quantity -= (int) $item['quantity'];
                $batch->save();

                $invoiceItem = DB::table('prod_purch')->where('id', $item['invoice_item_id'])->where('purchase_invoice_id', $id)->where('batch_id', $item['batch_id'])->lockForUpdate()->first();
                if (!$invoiceItem) throw new \DomainException('Purchase return line does not belong to this invoice and batch.');
                $alreadyReturned = (int) DB::table('purch_returns')->where('invoice_item_id', $invoiceItem->id)->sum('quantity');
                if ($alreadyReturned + (int) $item['quantity'] > (int) $invoiceItem->amount) throw new \DomainException('Returned quantity exceeds purchased quantity.');
                $historicalCostMinor = $this->moneyToMinor($invoiceItem->purchase_price ?? $invoiceItem->price);
                $supplierCreditMinor = $this->moneyToMinor($item['supplier_credit_unit_amount'] ?? number_format($historicalCostMinor / 100, 2, '.', ''));
                $unitCost = number_format($historicalCostMinor / 100, 2, '.', '');
                $supplierCredit = number_format($supplierCreditMinor / 100, 2, '.', '');
                $lineTotalMinor = (int) $item['quantity'] * $supplierCreditMinor;
                $lineTotal = number_format($lineTotalMinor / 100, 2, '.', '');
                $total_price += $lineTotalMinor / 100;

                $items[] = [
                    'invoice_id' => $id,
                    'invoice_item_id' => $item['invoice_item_id'],
                    'batch_id' => $item['batch_id'],
                    'quantity' => $item['quantity'],
                    'return_price' => $supplierCredit,
                    'cost_price' => $unitCost,
                    'line_total_return' => $lineTotal,
                    'reason' => $item['reasone'] ?? null,
                    'created_by' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

            }

            foreach ($items as &$returnRow) $returnRow['document_number'] = app(DocumentNumberService::class)->next('purchase_return','PRET');
            unset($returnRow);
            foreach ($items as $returnRow) {
                $returnId = DB::table('purch_returns')->insertGetId($returnRow);
                Stock::query()->create([
                    'batch_id' => $returnRow['batch_id'], 'quantity' => (int) $returnRow['quantity'], 'type' => 'PURCHASE_RETURN_OUT',
                    'reference_type' => 'purchase_return', 'reference_id' => $returnId, 'user_id' => $user->id, 'created_at' => now(),
                ]);
                app(AccountingPostingService::class)->postPurchaseReturn($returnId);
            }
            app(PrintIdentityService::class)->capture('purchase_return', $returnId);

            return [
                'status' => true,
                'message' => 'Purchase return completed successfully',
                'invoice_id' => $id,
                'total_return' => $total_price,
            ];
        });
    }

    private function salesHistoryQuery()
    {
        return DB::table('invoice_returns as returns')
            ->join('sales_invoices as invoices', 'invoices.id', '=', 'returns.invoice_id')
            ->join('products', 'products.id', '=', 'returns.product_id')
            ->join('product_batches as batches', 'batches.id', '=', 'returns.batch_id')
            ->leftJoin('users', 'users.id', '=', 'returns.created_by')
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->leftJoin('prod_sales as original_lines', function ($join) {
                $join->on('original_lines.sales_invoice_id', '=', 'returns.invoice_id')
                    ->on('original_lines.batch_id', '=', 'returns.batch_id');
            })
            ->leftJoin('branches', 'branches.id', '=', 'invoices.branch_id')
            ->leftJoin('units', 'units.id', '=', 'original_lines.transaction_unit_id')
            ->select([
                'returns.id', 'returns.document_number', 'returns.invoice_id as source_invoice_id', 'invoices.document_number as source_document_number', 'returns.created_at', 'returns.updated_at',
                'returns.quantity', 'returns.price', 'returns.original_returnable_unit_amount', 'returns.customer_credit_unit_amount', 'returns.return_to_inventory', 'returns.adjustment_reason', 'returns.reason',
                DB::raw('returns.quantity * COALESCE(returns.original_returnable_unit_amount, returns.price) as gross_original_return_value'),
                DB::raw('returns.quantity * COALESCE(returns.customer_credit_unit_amount, returns.price) as customer_credit_value'),
                DB::raw('returns.quantity * (COALESCE(returns.original_returnable_unit_amount, returns.price) - COALESCE(returns.customer_credit_unit_amount, returns.price)) as return_deduction'),
                DB::raw('returns.quantity * COALESCE(returns.customer_credit_unit_amount, returns.price) as total_returned_value'),
                'returns.created_by as user_id', 'users.name as user_name', 'invoices.customer_id', 'customers.name as customer_name',
                'invoices.branch_id', 'branches.name as branch_name', 'branches.code as branch_code',
                'returns.product_id', 'products.name as product_name', 'products.barcode as product_barcode',
                'returns.batch_id', 'batches.batch_number', 'batches.expiry_date', 'units.name as unit_name', DB::raw('COALESCE(returns.original_sale_line_id, original_lines.id) as original_line_id'),
            ]);
    }

    private function moneyToMinor(mixed $value): int
    {
        $normalized = trim((string) $value);
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $normalized, $matches)) {
            throw new \InvalidArgumentException('Invalid monetary amount.');
        }
        $minor = ((int) $matches[2] * 100) + (int) str_pad($matches[3] ?? '', 2, '0');
        return ($matches[1] ?? '') === '-' ? -$minor : $minor;
    }

    private function purchaseHistoryQuery()
    {
        return DB::table('purch_returns as returns')
            ->join('purchase_invoices as invoices', 'invoices.id', '=', 'returns.invoice_id')
            ->join('product_batches as batches', 'batches.id', '=', 'returns.batch_id')
            ->join('products', 'products.id', '=', 'batches.product_id')
            ->leftJoin('users', 'users.id', '=', 'returns.created_by')
            ->leftJoin('providers', 'providers.id', '=', 'invoices.provider_id')
            ->select([
                'returns.id', 'returns.document_number', 'returns.invoice_id as source_invoice_id', 'invoices.document_number as source_document_number', 'returns.created_at', 'returns.updated_at',
                'returns.quantity', 'returns.return_price', 'returns.cost_price', 'returns.line_total_return as total_returned_value',
                DB::raw('returns.quantity * returns.cost_price as original_inventory_value'),
                DB::raw('returns.line_total_return as supplier_credit_value'),
                DB::raw('(returns.quantity * returns.cost_price) - returns.line_total_return as inventory_adjustment'),
                'returns.reason',
                'returns.created_by as user_id', 'users.name as user_name', 'products.id as product_id', 'products.name as product_name',
                'products.barcode as product_barcode', 'returns.batch_id', 'batches.batch_number', 'batches.expiry_date',
                'providers.id as provider_id', 'providers.name as provider_name',
                'returns.invoice_item_id as original_line_id',
            ]);
    }
}
