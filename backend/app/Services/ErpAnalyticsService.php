<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ErpAnalyticsService
{
    public function customer360(Request $r, int $id): array
    {
        $p = DB::table('customers')->find($id);
        abort_unless($p, 404);
        $aging = $this->aging($r, 'customer', $id);
        $sales = $this->date(DB::table('sales_invoices')->where('customer_id', $id)->whereNull('deleted_at'), $r, 'date');
        $returns = $this->date(DB::table('invoice_returns as x')->join('sales_invoices as i', 'i.id', '=', 'x.invoice_id')->where('i.customer_id', $id), $r, 'x.created_at');
        $receipts = $this->date(DB::table('receipt_vouchers')->where('customer_id', $id)->where('status', 'posted'), $r, 'date');
        $cheques = $this->date(DB::table('checks')->where('customer_id', $id)->where('direction', 'in'), $r, 'created_at');
        $activity = $this->partyActivity($r, 'customer', $id, 5);
        $returnTotal = (float) ((clone $returns)->selectRaw('COALESCE(SUM(x.quantity * COALESCE(x.customer_credit_unit_amount, x.price)),0) total')->value('total') ?? 0);
        $out = ['profile' => $p, 'current_balance' => app(AccountingReportService::class)->customerStatement($id, null, $r->to)['balance'], 'credit_state' => ['limit' => $p->credit_limit, 'warning_threshold' => $p->credit_warning_threshold, 'block_on_limit' => (bool) $p->block_on_limit, 'available_credit' => $p->credit_limit === null ? null : round((float) $p->credit_limit - max(0, (float) ($aging['data'][0]['total_outstanding'] ?? 0)), 2)], 'ar_aging' => $aging, 'sales_total' => (float) (clone $sales)->sum('total_price'), 'sales_return_total' => $returnTotal, 'receipts_total' => (float) (clone $receipts)->sum('amount'), 'incoming_cheque_summary' => ['count' => (clone $cheques)->count(), 'amount' => (float) (clone $cheques)->sum('amount'), 'pending_amount' => (float) (clone $cheques)->where('status', 'pending')->sum('amount')], 'outstanding_invoices' => $aging['data'][0]['invoices'] ?? [], 'recent_activity' => $activity['rows'], 'activity_endpoint' => "/api/customers/$id/360/activity"];
        if ($r->user()->hasPermission('reports.view_profit')) {
            $out['profitability'] = ['revenue' => (float) (clone $sales)->sum('total_price') - $returnTotal, 'gross_profit' => (float) (clone $sales)->sum('profit') - $returnTotal];
        }

return $out;
    }

    public function supplier360(Request $r, int $id): array
    {
        $p = DB::table('providers')->find($id);
        abort_unless($p, 404);
        $aging = $this->aging($r, 'supplier', $id);
        $purchases = $this->date(DB::table('purchase_invoices')->where('provider_id', $id)->where('status', 'posted'), $r, 'date');
        $returns = $this->date(DB::table('purch_returns as x')->join('purchase_invoices as i', 'i.id', '=', 'x.invoice_id')->where('i.provider_id', $id), $r, 'x.created_at');
        $payments = $this->date(DB::table('payment_vouchers')->where('provider_id', $id)->where('status', 'posted'), $r, 'date');
        $cheques = $this->date(DB::table('checks')->where('provider_id', $id)->where('direction', 'out'), $r, 'created_at');
        $activity = $this->partyActivity($r, 'supplier', $id, 5);

        return ['profile' => $p, 'current_ap' => app(AccountingReportService::class)->supplierStatement($id, null, $r->to)['balance'], 'ap_aging' => $aging, 'purchases' => ['count' => (clone $purchases)->count(), 'total' => (float) (clone $purchases)->sum('amount')], 'purchase_returns' => ['count' => (clone $returns)->count(), 'total' => (float) (clone $returns)->sum('x.line_total_return')], 'payments' => ['count' => (clone $payments)->count(), 'total' => (float) (clone $payments)->sum('amount')], 'outgoing_cheques' => ['count' => (clone $cheques)->count(), 'total' => (float) (clone $cheques)->sum('amount'), 'pending' => (float) (clone $cheques)->where('status', 'pending')->sum('amount')], 'outstanding_purchases' => $aging['data'][0]['invoices'] ?? [], 'products_supplied' => DB::table('prod_purch as l')->join('purchase_invoices as i', 'i.id', '=', 'l.purchase_invoice_id')->join('products as p', 'p.id', '=', 'l.product_id')->where('i.provider_id', $id)->where('i.status', 'posted')->distinct()->get(['p.id', 'p.name', 'p.barcode']), 'preferred_supplier' => null, 'preferred_supplier_supported' => false, 'recent_activity' => $activity['rows'], 'activity_endpoint' => "/api/providers/$id/360/activity"];
    }

    public function partyActivity(Request $r, string $side, int $id, ?int $forcedPer = null): array
    {
        $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'activity_type' => 'nullable|string', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        $events = collect();
        if ($side === 'customer') {
            $this->append($events, $this->date(DB::table('sales_invoices')->where('customer_id', $id), $r, 'date')->get(), fn ($x) => $this->event($x->deleted_at ? 'cancellation' : 'sale', $x->id, $x->date, $x->document_number ?? 'Legacy sales invoice (no document number)', $x->total_price, $x->deleted_at ? 'cancelled' : 'posted'));
            $this->append($events, $this->date(DB::table('invoice_returns as x')->join('sales_invoices as i', 'i.id', '=', 'x.invoice_id')->where('i.customer_id', $id)->whereNull('x.sales_return_id')->select('x.*'), $r, 'x.created_at')->get(), fn ($x) => $this->event('sales_return', $x->id, $x->created_at, $x->document_number ?? 'Legacy sales return (no document number)', (float) $x->quantity * (float) ($x->customer_credit_unit_amount ?? $x->price), 'posted'));
            $this->append($events, $this->date(DB::table('sales_returns')->where('customer_id', $id), $r, 'return_date')->get(), fn ($x) => $this->event('sales_return', $x->id, $x->return_date, $x->document_number, $x->customer_credit_total, $x->status));
            $this->append($events, $this->date(DB::table('receipt_vouchers')->where('customer_id', $id), $r, 'date')->get(), fn ($x) => $this->event('receipt', $x->id, $x->date, $x->document_number ?? 'Legacy receipt (no document number)', $x->amount, $x->status));
            $cq = DB::table('checks')->where('customer_id', $id)->where('direction', 'in');
        } else {
            $this->append($events, $this->date(DB::table('purchase_invoices')->where('provider_id', $id), $r, 'date')->get(), fn ($x) => $this->event('purchase', $x->id, $x->date, $x->document_number ?? 'Legacy purchase invoice (no document number)', $x->amount, $x->status));
            $this->append($events, $this->date(DB::table('purch_returns as x')->join('purchase_invoices as i', 'i.id', '=', 'x.invoice_id')->where('i.provider_id', $id)->select('x.*'), $r, 'x.created_at')->get(), fn ($x) => $this->event('purchase_return', $x->id, $x->created_at, $x->document_number ?? 'Legacy purchase return (no document number)', $x->line_total_return, 'posted'));
            $this->append($events, $this->date(DB::table('payment_vouchers')->where('provider_id', $id), $r, 'date')->get(), fn ($x) => $this->event('payment', $x->id, $x->date, $x->document_number ?? 'Legacy supplier payment (no document number)', $x->amount, $x->status));
            $cq = DB::table('checks')->where('provider_id', $id)->where('direction', 'out');
        }
        $this->append($events, $this->date($cq, $r, 'created_at')->get(), fn ($x) => $this->event('cheque', $x->id, $x->created_at, "Cheque $x->check_number", $x->amount, $x->status, ['due_date' => $x->due_date, 'cleared_at' => $x->cleared_at, 'bounced_at' => $x->bounced_at]));
        if ($r->activity_type) {
            $events = $events->where('activity_type', $r->activity_type);
        }$events = $events->sortByDesc('timestamp')->values();

        return $this->paginate($events, $forcedPer ?? $r->integer('per_page', 25), $forcedPer ? 1 : $r->integer('page', 1));
    }

    public function product360(Request $r, int $id): array
    {
        $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'branch_id' => 'nullable|exists:branches,id', 'per_page' => 'nullable|integer|min:1|max:100']);
        $p = DB::table('products')->find($id);
        abort_unless($p, 404);
        $cost = $r->user()->hasPermission('reports.view_cost');
        $profit = $r->user()->hasPermission('reports.view_profit');
        $reportRequest = Request::create('/', 'GET', $r->query());
        $report = app(ProductReportingService::class)->productReport($id, $reportRequest);
        $batches = DB::table('product_batches')->where('product_id', $id)->when($r->branch_id, fn ($q, $x) => $q->where('branch_id', $x))->get()->map(function ($b) use ($cost) {
            $b->expiry_state = $b->expiry_date === null ? 'no_expiry' : (now()->greaterThan($b->expiry_date) ? 'expired' : (now()->addDays(30)->greaterThanOrEqualTo($b->expiry_date) ? 'expiring' : 'valid'));
            if (! $cost) {
                unset($b->purchase_price);
            }

return $b;
        });
        $mov = $this->stockQuery($id, null, $r)->orderByDesc('s.id')->paginate($r->integer('per_page', 25));
        $purchaseValue = (float) ($this->date(DB::table('prod_purch as l')->join('purchase_invoices as i', 'i.id', '=', 'l.purchase_invoice_id')->where('l.product_id', $id)->where('i.status', 'posted'), $r, 'i.date')->selectRaw('COALESCE(SUM(l.amount*l.purchase_price),0) total')->value('total') ?? 0);
        $out = ['profile' => $p, 'controlled_status' => ['is_controlled' => (bool) $p->is_controlled, 'schedule' => $p->control_schedule], 'units' => DB::table('product_units as pu')->join('units as u', 'u.id', '=', 'pu.unit_id')->where('pu.product_id', $id)->get(), 'stock_by_branch' => app(BatchBranchService::class)->sellableStockByBranchForProduct($id), 'batches' => $batches, 'expiry_state' => ['expired' => $batches->where('expiry_state', 'expired')->count(), 'expiring' => $batches->where('expiry_state', 'expiring')->count()], 'reorder_state' => ['minimum_stock' => $p->minimum_stock, 'reorder_level' => $p->reorder_level, 'target_quantity' => $p->reorder_quantity], 'purchase_quantity' => $report['purchased_quantity'], 'purchase_returns' => $report['purchase_return_quantity'], 'sold_quantity' => $report['sold_quantity'], 'sales_returns' => $report['sales_return_quantity'], 'prescription_dispensing' => $report['prescription_dispensed_quantity'], 'stock_adjustments' => (float) $this->stockQuery($id, null, $r)->where('s.reference_type', 'stock_adjustment')->sum('s.quantity'), 'stock_count_differences' => (float) DB::table('stock_count_lines')->where('product_id', $id)->sum('difference'), 'transfers' => (float) $this->stockQuery($id, null, $r)->where('s.reference_type', 'stock_transfer')->sum('s.quantity'), 'recall_quarantine' => DB::table('product_batches')->where('product_id', $id)->whereIn('inventory_state', ['recalled', 'quarantined'])->get(['id', 'batch_number', 'inventory_state', 'state_reason']), 'recent_movements' => $mov];
        if ($cost) {
            $out['purchase_value'] = round($purchaseValue, 2);
        }if ($profit) {
            $out['revenue'] = $report['sales_revenue'];
            $out['cogs'] = $report['cogs'];
            $out['gross_profit'] = $report['gross_profit'];
        }

return $out;
    }

    public function batch360(Request $r, int $id): array
    {
        $b = DB::table('product_batches')->find($id);
        abort_unless($b, 404);
        $cost = $r->user()->hasPermission('reports.view_cost') || $r->user()->hasPermission('inventory.view_cost');
        $sales = DB::table('prod_sales as l')->join('sales_invoices as i', 'i.id', '=', 'l.sales_invoice_id')->where('l.batch_id', $id)->get(['l.*', 'i.document_number', 'i.customer_id', 'i.date']);
        $purchases = DB::table('prod_purch as l')->join('purchase_invoices as i', 'i.id', '=', 'l.purchase_invoice_id')->where('l.batch_id', $id)->get(['l.*', 'i.document_number', 'i.provider_id', 'i.date']);
        $mov = $this->stockQuery($b->product_id, $id, $r)->orderByDesc('s.id')->paginate(min(100, max(1, $r->integer('per_page', 25))));
        $missing = $this->stockQuery($b->product_id, $id, $r)->where(fn ($q) => $q->whereNull('s.reference_type')->orWhereNull('s.reference_id'))->count();
        $out = ['product' => DB::table('products')->find($b->product_id), 'branch' => DB::table('branches')->find($b->branch_id), 'batch_number' => $b->batch_number, 'expiry' => $b->expiry_date, 'current_state' => $b->inventory_state, 'current_quantity' => $b->quantity, 'originating_purchases' => $purchases, 'sales' => $sales, 'sales_returns' => DB::table('invoice_returns')->where('batch_id', $id)->get(), 'purchase_returns' => DB::table('purch_returns')->where('batch_id', $id)->get(), 'prescription_dispensing' => DB::table('prescription_dispense_allocations')->where('batch_id', $id)->get(), 'adjustments' => DB::table('stock_adjustments')->where('batch_id', $id)->get(), 'stock_counts' => DB::table('stock_count_lines')->where('batch_id', $id)->get(), 'transfers' => DB::table('stock_transfer_lines')->where('source_batch_id', $id)->orWhere('destination_batch_id', $id)->get(), 'recall_quarantine_state' => ['state' => $b->inventory_state, 'reason' => $b->state_reason, 'recalls' => DB::table('batch_recalls')->where('batch_id', $id)->get()], 'recent_movement_timeline' => $mov, 'linkage_complete' => $missing === 0, 'unlinked_movement_count' => $missing];
        if ($cost) {
            $out['unit_cost'] = $b->purchase_price;
        } else {
            unset($out['originating_purchases'], $out['purchase_returns']);
            $out = $this->stripSensitiveCostFields($out);
        }

return $out;
    }

    private function stripSensitiveCostFields(mixed $value): mixed
    {
        $sensitive = ['purchase_price','cost_price','average_cost','historical_cost','inventory_value','purchase_value','unit_cost','cost_basis','cogs','profit','gross_profit','profit_margin','margin','markup','supplier_balance','accounts_payable'];
        if ($value instanceof \Illuminate\Support\Collection) {
            return $value->map(fn ($item) => $this->stripSensitiveCostFields($item));
        } elseif ($value instanceof \Illuminate\Contracts\Pagination\Paginator) {
            return $value->through(fn ($item) => $this->stripSensitiveCostFields($item));
        } elseif (is_array($value)) {
            foreach ($sensitive as $key) unset($value[$key]);
            foreach ($value as $key => $item) $value[$key] = $this->stripSensitiveCostFields($item);
        } elseif (is_object($value)) {
            foreach ($sensitive as $key) unset($value->{$key});
            foreach (get_object_vars($value) as $key => $item) $value->{$key} = $this->stripSensitiveCostFields($item);
        }
        return $value;
    }

    public function controlled(Request $r, bool $all = false): array
    {
        $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'product_id' => 'nullable|exists:products,id', 'batch_id' => 'nullable|exists:product_batches,id', 'branch_id' => 'nullable|exists:branches,id', 'movement_type' => 'nullable|string', 'user_id' => 'nullable|exists:users,id', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        $audit = $r->user()->hasPermission('controlled.audit');
        $q = $this->stockQuery($r->integer('product_id') ?: null, $r->integer('batch_id') ?: null, $r)->where('p.is_controlled', true)->when($r->movement_type, fn ($q, $x) => $q->where('s.type', $x))->when($r->user_id, fn ($q, $x) => $q->where('s.user_id', $x))->leftJoin('users as u', 'u.id', '=', 's.user_id')->select('s.*', 'p.name as product', 'b.batch_number', 'u.name as actor');
        $allRows = $q->orderBy('s.created_at')->orderBy('s.id')->get()->map(function ($x) use ($audit) {
            $out = $this->isOut($x->type);
            $x->timestamp = $x->created_at;
            $x->in = $out ? 0 : (float) $x->quantity;
            $x->out = $out ? (float) $x->quantity : 0;
            $x->source_label = str($x->reference_type)->replace('_', ' ')->title().' #'.$x->reference_id;
            if ($audit && $x->reference_type === 'prescription_dispense') {
                $rx = DB::table('prescriptions as rx')->join('patients as pt', 'pt.id', '=', 'rx.patient_id')->where('rx.id', $x->reference_id)->first(['rx.id as prescription_id', 'rx.prescriber_name', 'pt.id as patient_id', 'pt.full_name as patient_name']);
                $x->patient = $rx ? ['id' => $rx->patient_id, 'name' => $rx->patient_name] : null;
                $x->prescription = $rx ? ['id' => $rx->prescription_id] : null;
                $x->prescriber = $rx?->prescriber_name;
            }

return $x;
        });
        $summary = ['opening' => $allRows->where('type', 'OPENING_STOCK_IN')->sum('in'), 'received' => $allRows->where('type', 'PURCHASE_IN')->sum('in'), 'sold' => $allRows->where('type', 'SALE_OUT')->sum('out'), 'prescription_dispensed' => $allRows->where('type', 'PRESCRIPTION_DISPENSE_OUT')->sum('out'), 'returns' => $allRows->filter(fn ($x) => str_contains($x->type, 'RETURN'))->sum(fn ($x) => $x->in - $x->out), 'adjustments' => $allRows->filter(fn ($x) => str_contains($x->type, 'ADJUST'))->sum(fn ($x) => $x->in - $x->out), 'transfers' => $allRows->filter(fn ($x) => str_contains($x->type, 'TRANSFER'))->sum(fn ($x) => $x->in - $x->out)];
        $summary['closing'] = array_sum([$summary['opening'], $summary['received'], $summary['returns'], $summary['adjustments'], $summary['transfers']]) - $summary['sold'] - $summary['prescription_dispensed'];
        $paged = $all ? $this->paginate($allRows, max(1, $allRows->count()), 1) : $this->paginate($allRows, $r->integer('per_page', 25), $r->integer('page', 1));

        return ['rows' => $paged['rows'], 'meta' => $paged['meta'], 'summary' => array_map(fn ($x) => round((float) $x, 3), $summary), 'patient_identity_included' => $audit, 'privacy_policy' => $audit ? 'controlled.audit' : 'controlled.view'];
    }

    public function recall(Request $r, int $id): array
    {
        $recall = DB::table('batch_recalls')->find($id);
        abort_unless($recall, 404);
        $b = DB::table('product_batches')->find($recall->batch_id);
        $moves = DB::table('stock')->where('batch_id', $b->id)->orderBy('created_at')->get();
        $purchaseLines = DB::table('prod_purch as l')->join('purchase_invoices as i', 'i.id', '=', 'l.purchase_invoice_id')->join('providers as p', 'p.id', '=', 'i.provider_id')->where('l.batch_id', $b->id)->get(['i.id as purchase_id', 'i.document_number', 'p.id as supplier_id', 'p.name as supplier', 'l.amount as quantity']);
        $saleLines = DB::table('prod_sales as l')->join('sales_invoices as i', 'i.id', '=', 'l.sales_invoice_id')->leftJoin('customers as c', 'c.id', '=', 'i.customer_id')->where('l.batch_id', $b->id)->get(['i.id as sale_id', 'i.document_number', 'c.id as customer_id', 'c.name as customer', 'l.amount as quantity']);
        $disp = DB::table('prescription_dispense_allocations as a')->join('prescriptions as rx', 'rx.id', '=', 'a.prescription_id')->leftJoin('patients as pt', 'pt.id', '=', 'rx.patient_id')->where('a.batch_id', $b->id)->get(['a.*', 'rx.prescriber_name', 'pt.id as patient_id', 'pt.full_name as patient_name']);
        if (! $r->user()->hasPermission('controlled.audit')) {
            $disp->each(fn ($x) => [$x->patient_id = null, $x->patient_name = null, $x->prescriber_name = null]);
        }$unlinked = $moves->filter(fn ($x) => ! $x->reference_type || ! $x->reference_id);

        return ['product' => DB::table('products')->find($b->product_id), 'batch' => $b, 'recall_reason' => $recall->reason, 'recall_status' => $recall->status, 'issued_at' => $recall->issued_at, 'source_purchases' => $purchaseLines, 'suppliers' => $purchaseLines->unique('supplier_id')->values()->map(fn ($x) => ['id' => $x->supplier_id, 'name' => $x->supplier]), 'purchased_quantity' => (float) $purchaseLines->sum('quantity'), 'current_quantity' => (float) $b->quantity, 'sales_quantity' => (float) $saleLines->sum('quantity'), 'sales_documents' => $saleLines, 'customers' => $saleLines->unique('customer_id')->values()->map(fn ($x) => ['id' => $x->customer_id, 'name' => $x->customer]), 'sales_returns' => DB::table('invoice_returns')->where('batch_id', $b->id)->get(), 'prescription_dispensing' => $disp, 'adjustments' => DB::table('stock_adjustments')->where('batch_id', $b->id)->get(), 'transfers' => DB::table('stock_transfer_lines')->where('source_batch_id', $b->id)->orWhere('destination_batch_id', $b->id)->get(), 'stock_count_changes' => DB::table('stock_count_lines')->where('batch_id', $b->id)->where('difference', '!=', 0)->get(), 'linkage_complete' => $unlinked->isEmpty(), 'linkage_gaps' => ['count' => $unlinked->count(), 'movement_types' => $unlinked->pluck('type')->countBy()]];
    }

    private function aging(Request $r, string $side, int $id): array
    {
        $q = Request::create('/', 'GET', array_merge($r->query(), ['party_id' => $id, 'detail' => true]));
        $q->setUserResolver(fn () => $r->user());

        return app(AgingService::class)->report($q, $side);
    }

    private function date($q, Request $r, string $c)
    {
        return $q->when($r->from, fn ($q, $x) => $q->whereDate($c, '>=', $x))->when($r->to, fn ($q, $x) => $q->whereDate($c, '<=', $x));
    }

    private function append(Collection $to, $rows, $map): void
    {
        foreach ($rows as $x) {
            $to->push($map($x));
        }
    }

    private function event($type, $id, $at, $label, $amount, $status, $extra = []): array
    {
        return array_merge(['activity_type' => $type, 'source_type' => $type, 'source_id' => $id, 'source_label' => $label, 'timestamp' => (string) $at, 'amount' => (float) $amount, 'status' => $status], $extra);
    }

    private function paginate(Collection $c,int $per,int $page): array
    {
        $total = $c->count();

        return ['rows' => $c->slice(($page - 1) * $per,$per)->values(), 'meta' => ['current_page' => $page, 'per_page' => $per, 'total' => $total, 'last_page' => max(1,(int) ceil($total / max(1,$per)))]];
    }

    private function stockQuery($product,$batch,Request $r)
    {
        $query = DB::table('stock as s')->join('product_batches as b','b.id','=','s.batch_id')->join('products as p','p.id','=','b.product_id')->when($product,fn ($q,$x) => $q->where('p.id',$x))->when($batch,fn ($q,$x) => $q->where('b.id',$x))->when($r->branch_id,fn ($q,$x) => $q->where('s.branch_id',$x));
        app(BusinessTimeService::class)->applyUtcDateFilter($query, 's.created_at', $r->from, $r->to);

        return $query;
    }

    private function isOut(string $t): bool
    {
        return str_contains(strtoupper($t),'OUT') || in_array($t,['SALE', 'PRESCRIPTION_DISPENSE'],true);
    }
}
