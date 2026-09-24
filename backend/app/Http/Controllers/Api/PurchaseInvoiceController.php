<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePurchaseInvoiceRequest;
use App\Http\Requests\UpdatePurchaseInvoiceRequest;
use App\Models\PurchaseInvoice;
use App\Services\PurchaseInvoiceService;
use Illuminate\Http\Request;
use App\Exceptions\AccountingPeriodException;

class PurchaseInvoiceController extends Controller
{

    private PurchaseInvoiceService $purchaseInvoiceService;
    public function __construct(PurchaseInvoiceService $purchaseInvoiceService)
    {
        $this->purchaseInvoiceService = $purchaseInvoiceService;
    }
    public function index(Request $request)
    {
        try {
            $request->validate(['invoice_id' => 'nullable|integer', 'document_number' => 'nullable|string|max:100', 'provider_id' => 'nullable|integer', 'product_id' => 'nullable|integer', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'due_from' => 'nullable|date', 'due_to' => 'nullable|date|after_or_equal:due_from', 'status' => 'nullable|string|max:30', 'total_min' => 'nullable|numeric|min:0', 'total_max' => 'nullable|numeric|gte:total_min', 'sort' => 'nullable|in:id,date,due_date,amount,status', 'direction' => 'nullable|in:asc,desc']);
            $purch_invoices = \DB::table('purchase_invoices as purchases')
                ->leftJoin('providers', 'providers.id', '=', 'purchases.provider_id')
                ->select('purchases.*', 'providers.name as provider_name')
                ->when($request->filled('invoice_id'), fn ($q) => $q->where('purchases.id', $request->invoice_id))
                ->when($request->filled('document_number'), fn ($q) => $q->where('purchases.document_number', 'like', '%'.$request->document_number.'%'))
                ->when($request->filled('provider_id'), fn ($q) => $q->where('purchases.provider_id', $request->provider_id))
                ->when($request->filled('provider_search'), fn ($q) => $q->where('providers.name', 'like', '%'.$request->provider_search.'%'))
                ->when($request->filled('product_id'), fn ($q) => $q->whereExists(fn ($l) => $l->selectRaw('1')->from('prod_purch')->whereColumn('prod_purch.purchase_invoice_id', 'purchases.id')->where('prod_purch.product_id', $request->product_id)))
                ->when($request->filled('product_search'), fn ($q) => $q->whereExists(fn ($l) => $l->selectRaw('1')->from('prod_purch')->join('products', 'products.id', '=', 'prod_purch.product_id')->whereColumn('prod_purch.purchase_invoice_id', 'purchases.id')->where(fn ($p) => $p->where('products.name', 'like', '%'.$request->product_search.'%')->orWhere('products.barcode', 'like', '%'.$request->product_search.'%'))))
                ->when($request->filled('from'), fn ($q) => $q->whereDate('purchases.date', '>=', $request->from))->when($request->filled('to'), fn ($q) => $q->whereDate('purchases.date', '<=', $request->to))
                ->when($request->filled('due_from'), fn ($q) => $q->whereDate('purchases.due_date', '>=', $request->due_from))->when($request->filled('due_to'), fn ($q) => $q->whereDate('purchases.due_date', '<=', $request->due_to))
                ->when($request->filled('status'), fn ($q) => $q->where('purchases.status', $request->status))
                ->when($request->filled('total_min'), fn ($q) => $q->where('purchases.total_after_discount', '>=', $request->total_min))->when($request->filled('total_max'), fn ($q) => $q->where('purchases.total_after_discount', '<=', $request->total_max))
                ->orderBy('purchases.'.($request->sort ?? 'id'), $request->direction === 'asc' ? 'asc' : 'desc')
                ->when(($request->sort ?? 'id') !== 'id', fn ($q) => $q->orderByDesc('purchases.id'))
                ->paginate(min(max((int) $request->input('per_page', 15), 1), 100));

            $purch_invoices->through(function ($invoice) {
                $invoice->provider = (object) [
                    'id' => $invoice->provider_id,
                    'name' => $invoice->provider_name,
                ];
                unset($invoice->provider_name);
                return $invoice;
            });
            return $this->collection($purch_invoices, 'Purchase invoices retrieved.');
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error.',
                'errors' => (object) []
            ], 500);
        }
    }


    public function store(StorePurchaseInvoiceRequest $request)
    {
        try {
            $user = auth()->user();
            $result = $this->purchaseInvoiceService->store($request, $user);
            if ($result) {
                return $this->success($result, 201, 'Purchase invoice created.');
            } else {
                return $this->failure('Purchase invoice could not be created.', 500);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error.',
                'errors' => (object) []
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $purch_invoices = $this->purchaseInvoiceService->show($id);
            if (!$purch_invoices) {
                return $this->failure('Purchase invoice not found.', 404);
            } else {
                return $this->success($purch_invoices, 200, 'Purchase invoice retrieved.');

            }
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error.',
                'errors' => (object) []
            ], 500);
        }
    }
    public function post($id)
    {
        try {
            $user = auth()->user();
            $res = $this->purchaseInvoiceService->post($id, $user);
            if (!$res) {
                return $this->failure('Purchase invoice is not available for posting.', 409);
            }
            return $this->success($res, 200, 'Purchase invoice posted.');
        } catch (\Throwable $e) {
            if ($e instanceof AccountingPeriodException) throw $e;
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Server error.',
                'errors' => (object) []
            ], 500);
        }
    }

    public function cancel($id)
    {
        try {
            $user = auth()->user();
            $res = $this->purchaseInvoiceService->cancel($id, $user);
            if (!$res) {
                return $this->failure('Purchase invoice is not available for cancellation.', 409);
            }
            return $this->success($res, 200, 'Purchase invoice cancelled.');
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error.',
                'errors' => (object) []
            ], 500);
        }
    }
    public function update(UpdatePurchaseInvoiceRequest $request, $purchaseInvoice)
    {
        try {
            $user = auth()->user();
            $res = $this->purchaseInvoiceService->update($request, $purchaseInvoice, $user);
            if (!$res) {
                return $this->failure('Purchase invoice not found or not editable.', 409);
            }
            return $this->success($res, 200, 'Purchase invoice updated.');
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error.',
                'errors' => (object) []
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PurchaseInvoice $purchaseInvoice)
    {
        //
    }
}
