<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BatchBranchService;
use App\Services\InventoryExpansionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryExpansionController extends Controller
{
    public function __construct(
        private InventoryExpansionService $service,
        private BatchBranchService $batchBranches,
    ) {}

    public function openingStore(Request $r)
    {
        $v = $r->validate(['branch_id' => 'nullable|exists:branches,id', 'effective_date' => 'required|date', 'notes' => 'nullable|string', 'lines' => 'required|array|min:1', 'lines.*.type' => 'required|in:inventory,customer_ar,supplier_ap,cash,bank,incoming_cheque,outgoing_cheque', 'lines.*.product_id' => 'nullable|exists:products,id', 'lines.*.unit_id' => 'nullable|exists:units,id', 'lines.*.customer_id' => 'nullable|exists:customers,id', 'lines.*.provider_id' => 'nullable|exists:providers,id', 'lines.*.batch_number' => 'nullable|string', 'lines.*.expiry_date' => 'nullable|date', 'lines.*.quantity' => 'nullable|numeric|gt:0', 'lines.*.unit_cost' => 'nullable|numeric|min:0', 'lines.*.sale_price' => 'nullable|numeric|min:0', 'lines.*.amount' => 'nullable|numeric|gt:0']);
        $id = $this->service->createOpening($v, $r->user()->id);

        return $this->success(DB::table('opening_balance_sessions')->find($id), 201, 'Opening session created.');
    }

    public function openingValidate(int $id)
    {
        $this->service->validateOpening($id);

        return $this->success(DB::table('opening_balance_sessions')->find($id));
    }

    public function openingPost(Request $r, int $id)
    {
        $this->service->postOpening($id, $r->user()->id);

        return $this->success(DB::table('opening_balance_sessions')->find($id));
    }

    public function countStore(Request $r)
    {
        $v = $r->validate(['branch_id' => 'nullable|exists:branches,id', 'scope' => 'required|in:selected,full', 'product_ids' => 'required_if:scope,selected|array', 'product_ids.*' => 'exists:products,id', 'notes' => 'nullable|string']);
        $id = $this->service->createCount($v, $r->user()->id);

        $lines = DB::table('stock_count_lines as lines')
            ->join('products as products', 'products.id', '=', 'lines.product_id')
            ->join('product_batches as batches', 'batches.id', '=', 'lines.batch_id')
            ->where('lines.stock_count_id', $id)
            ->select([
                'lines.*',
                'products.name as product_name',
                'products.barcode as product_barcode',
                'batches.batch_number',
                'batches.expiry_date',
            ])
            ->get();

        return $this->success(['count' => DB::table('stock_counts')->find($id), 'lines' => $lines], 201);
    }

    public function countPost(Request $r, int $id)
    {
        $v = $r->validate(['lines' => 'required|array|min:1', 'lines.*.batch_id' => 'required|distinct|exists:product_batches,id', 'lines.*.counted_quantity' => 'required|numeric|min:0', 'lines.*.unit_id' => 'nullable|exists:units,id', 'lines.*.reason' => 'nullable|string', 'lines.*.note' => 'nullable|string']);
        $this->service->postCount($id, $v['lines'], $r->user()->id);

        return $this->success(DB::table('stock_counts')->find($id));
    }

    public function adjustmentStore(Request $r)
    {
        $v = $r->validate(['branch_id' => 'nullable|exists:branches,id', 'product_id' => 'required|exists:products,id', 'batch_id' => 'required|exists:product_batches,id', 'quantity' => 'required|numeric|not_in:0', 'unit_id' => 'nullable|exists:units,id', 'reason' => 'required|in:damaged,expired,found_stock,count_difference,administrative_correction,other', 'note' => 'nullable|string']);
        $id = $this->service->adjust($v, $r->user()->id);

        return $this->success(DB::table('stock_adjustments')->find($id), 201);
    }

    public function reorder(Request $r)
    {
        $r->validate(['branch_id' => 'nullable|exists:branches,id', 'product_id' => 'nullable|exists:products,id']);
        $branch = $r->integer('branch_id') ?: null;
        $stockByProduct = $this->batchBranches->sellableStockByProduct($branch);
        $rows = DB::table('products')->whereNull('deleted_at')
            ->when($r->integer('product_id'), fn ($query, $productId) => $query->where('id', $productId))
            ->get(['id', 'name', 'minimum_stock', 'reorder_level', 'reorder_quantity'])
            ->map(function ($x) use ($r, $branch, $stockByProduct) {
            $x->current_sellable_stock = (float) ($stockByProduct->get($x->id) ?? 0);
            if ($x->current_sellable_stock >= (float) $x->reorder_level) {
                return null;
            }
            $last = DB::table('prod_purch as l')->join('purchase_invoices as i', 'i.id', '=', 'l.purchase_invoice_id')->join('providers as p', 'p.id', '=', 'i.provider_id')->where('l.product_id', $x->id)->where('i.status', 'posted')->when($branch, fn ($q) => $q->where('i.branch_id', $branch))->orderByDesc('i.date')->orderByDesc('i.id')->first(['p.id', 'p.name', 'l.purchase_price', 'i.date']);
            $row = ['product' => ['id' => $x->id, 'name' => $x->name], 'branch' => $branch ? DB::table('branches')->find($branch) : null, 'current_sellable_stock' => (float) $x->current_sellable_stock, 'minimum_stock' => (float) $x->minimum_stock, 'reorder_level' => (float) $x->reorder_level, 'target_reorder_quantity' => (float) $x->reorder_quantity, 'suggested_reorder_quantity' => max((float) $x->reorder_quantity, (float) $x->reorder_level - (float) $x->current_sellable_stock), 'last_supplier' => $last ? ['id' => $last->id, 'name' => $last->name, 'purchase_date' => $last->date] : null, 'preferred_supplier' => null, 'preferred_supplier_supported' => false];
            if ($r->user()->hasPermission('reports.view_cost')) {
                $row['last_purchase_cost'] = $last ? (float) $last->purchase_price : null;
            }

return $row;
        })->filter()->values();

        return $this->success($rows);
    }

    public function batchState(Request $r, int $id)
    {
        $v = $r->validate(['state' => 'required|in:available,quarantined,recalled,archived', 'reason' => 'required_unless:state,available|string']);
        $b = DB::table('product_batches')->where('id', $id)->first();
        abort_unless($b, 404);
        DB::table('product_batches')->where('id', $id)->update(['inventory_state' => $v['state'], 'state_reason' => $v['reason'] ?? null, 'state_changed_by' => $r->user()->id, 'state_changed_at' => now()]);
        if ($v['state'] === 'recalled') {
            DB::table('batch_recalls')->insert(['product_id' => $b->product_id, 'batch_id' => $id, 'reason' => $v['reason'], 'issued_at' => now(), 'status' => 'active', 'actor_id' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        }

return $this->success(DB::table('product_batches')->find($id));
    }

    public function transferStore(Request $r)
    {
        $v = $r->validate(['source_branch_id' => 'required|exists:branches,id', 'destination_branch_id' => 'required|different:source_branch_id|exists:branches,id', 'notes' => 'nullable|string', 'lines' => 'required|array|min:1', 'lines.*.batch_id' => 'required|exists:product_batches,id', 'lines.*.quantity' => 'required|numeric|gt:0', 'lines.*.unit_id' => 'nullable|exists:units,id']);
        $id = $this->service->createTransfer($v, $r->user()->id);

        $lines = DB::table('stock_transfer_lines as lines')
            ->join('products', 'products.id', '=', 'lines.product_id')
            ->where('lines.transfer_id', $id)
            ->select('lines.*', 'products.name as product_name', 'products.barcode as product_barcode')
            ->get();

        return $this->success(['transfer' => DB::table('stock_transfers')->find($id), 'lines' => $lines], 201);
    }

    public function transferSend(Request $r, int $id)
    {
        $this->service->sendTransfer($id, $r->user()->id);

        return $this->success(DB::table('stock_transfers')->find($id));
    }

    public function transferReceive(Request $r, int $id)
    {
        $this->service->receiveTransfer($id, $r->user()->id);

        return $this->success(DB::table('stock_transfers')->find($id));
    }

    public function transferCancel(int $id)
    {
        $this->service->cancelTransfer($id);

        return $this->success(DB::table('stock_transfers')->find($id));
    }
}
