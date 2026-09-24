<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSalesInvoiceRequest;
use App\Exceptions\SalesInventoryException;
use App\Exceptions\AccountingPeriodException;
use App\Models\SalesInvoice;
use App\Services\SalesInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use Throwable;

class SalesInvoiceController extends Controller
{
    private SalesInvoiceService $SalesInvoiceService;
    public function __construct(SalesInvoiceService $salesInvoice)
    {
        $this->SalesInvoiceService = $salesInvoice;
    }
    public function cancel($id)
    {
        try {
            $result = $this->SalesInvoiceService->cancel($id, (int) auth()->user()->id);
            return $this->success(['id' => (int) $id], 200, $result['message']);
        } catch (\Throwable $e) {
            if ($e instanceof AccountingPeriodException) throw $e;
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) throw $e;
            report($e);
            return response()->json([
                'success' => false, 'message' => 'Server error.', 'errors' => (object) []
            ], 500);
        }

    }
    public function store(StoreSalesInvoiceRequest $request)
    {
        try {
            $user = auth()->user();
            $result = $this->SalesInvoiceService->store($request, $user);
            if ($result) {
                return $this->success($this->projectSensitiveFields($result, $user), 201, 'Sales invoice created.');
            } else {
                return $this->failure('Sales invoice could not be created.', 500);
            }
        } catch (SalesInventoryException $e) {
            return $this->failure($e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e->getMessage(), 422);
        } catch (\Throwable $e) {
            if ($e instanceof AccountingPeriodException) throw $e;
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) throw $e;
            report($e);
            return response()->json([
                'success' => false, 'message' => 'Server error.', 'errors' => (object) []
            ], 500);
        }
    }
    public function index(Request $request)
    {
        try {
            $request->validate([
                'invoice_id' => ['nullable', 'integer', 'min:1'],
                'document_number' => ['nullable', 'string', 'max:100'],
                'product_id' => ['nullable', 'integer', 'min:1'],
                'from' => ['nullable', 'date'],
                'to' => ['nullable', 'date', 'after_or_equal:from'],
                'status' => ['nullable', 'in:active,cancelled,all'],
                'payment_method' => ['nullable', 'string', 'max:50'],
                'customer_id' => ['nullable', 'integer'], 'total_min' => ['nullable', 'numeric', 'min:0'],
                'total_max' => ['nullable', 'numeric', 'gte:total_min'], 'paid_min' => ['nullable', 'numeric', 'min:0'],
                'paid_max' => ['nullable', 'numeric', 'gte:paid_min'], 'outstanding_min' => ['nullable', 'numeric', 'min:0'],
                'outstanding_max' => ['nullable', 'numeric', 'gte:outstanding_min'],
            ]);
            $salesInvoices = \DB::table('sales_invoices as sales')
                ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
                ->select('sales.*', 'customers.name as customer_identity_name')
                ->when($request->filled('invoice_id'), fn ($query) => $query->where('sales.id', $request->integer('invoice_id')))
                ->when($request->filled('document_number'), fn ($query) => $query->where('sales.document_number', 'like', '%'.$request->document_number.'%'))
                ->when($request->filled('customer_id'), fn ($query) => $query->where('sales.customer_id', $request->integer('customer_id')))
                ->when($request->filled('search'), function ($query) use ($request) {
                    $search = $request->string('search')->toString();
                    $query->where(fn ($nested) => $nested
                        ->where('sales.document_number', 'like', "%{$search}%")
                        ->orWhere('customers.name', 'like', "%{$search}%"));
                })
                ->when($request->filled('product_id'), fn ($query) => $query->whereExists(fn ($lines) => $lines
                    ->selectRaw('1')->from('prod_sales')->join('product_batches', 'product_batches.id', '=', 'prod_sales.batch_id')
                    ->whereColumn('prod_sales.sales_invoice_id', 'sales.id')->where('product_batches.product_id', $request->integer('product_id'))))
                ->when($request->filled('product_search'), fn ($query) => $query->whereExists(fn ($lines) => $lines
                    ->selectRaw('1')->from('prod_sales')->join('product_batches', 'product_batches.id', '=', 'prod_sales.batch_id')
                    ->join('products', 'products.id', '=', 'product_batches.product_id')
                    ->whereColumn('prod_sales.sales_invoice_id', 'sales.id')
                    ->where(fn ($product) => $product->where('products.name', 'like', '%'.$request->product_search.'%')->orWhere('products.barcode', 'like', '%'.$request->product_search.'%'))))
                ->when($request->filled('from'), fn ($query) => $query->whereDate('sales.date', '>=', $request->from))
                ->when($request->filled('to'), fn ($query) => $query->whereDate('sales.date', '<=', $request->to))
                ->when($request->get('status', 'all') === 'active', fn ($query) => $query->whereNull('sales.deleted_at'))
                ->when($request->get('status') === 'cancelled', fn ($query) => $query->whereNotNull('sales.deleted_at'))
                ->when($request->filled('payment_method'), fn ($query) => $query->where('sales.payment_method', $request->payment_method))
                ->when($request->filled('total_min'), fn ($q) => $q->where('sales.total_price', '>=', $request->total_min))
                ->when($request->filled('total_max'), fn ($q) => $q->where('sales.total_price', '<=', $request->total_max))
                ->when($request->filled('paid_min'), fn ($q) => $q->where('sales.paid_amount', '>=', $request->paid_min))
                ->when($request->filled('paid_max'), fn ($q) => $q->where('sales.paid_amount', '<=', $request->paid_max))
                ->when($request->filled('outstanding_min'), fn ($q) => $q->whereRaw('(sales.total_price - sales.paid_amount) >= ?', [$request->outstanding_min]))
                ->when($request->filled('outstanding_max'), fn ($q) => $q->whereRaw('(sales.total_price - sales.paid_amount) <= ?', [$request->outstanding_max]))
                ->orderByDesc('sales.id')
                ->paginate(min(max((int) $request->input('per_page', 15), 1), 100));
            $user = $request->user();
            $salesInvoices->through(function ($invoice) use ($user) {
                $invoice->status = $invoice->deleted_at ? 'cancelled' : 'posted';
                $invoice->cancelled = $invoice->deleted_at !== null;
                $invoice->customer = (object) ['id' => $invoice->customer_id, 'name' => $invoice->customer_identity_name];
                unset($invoice->customer_identity_name);
                return $this->projectSensitiveFields($invoice, $user);
            });
            return $this->collection($salesInvoices, 'Sales invoices retrieved.');
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false, 'message' => 'Server error.', 'errors' => (object) []
            ], 500);
        }
    }
    public function show($id)
    {
        try {
         $res=$this->SalesInvoiceService->show($id);
          if (!$res) {
                return $this->failure('Sales invoice not found.', 404);
            } else {
                return $this->success($this->projectSensitiveFields($res, auth()->user()), 200, 'Sales invoice retrieved.');

            }
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false, 'message' => 'Server error.', 'errors' => (object) []
            ], 500);
        }
    }

    public function customerProductPriceHistory(Request $request, int $customer, int $product)
    {
        $validated = $request->validate(['unit_id' => ['nullable', 'integer', 'min:1']]);
        abort_unless(DB::table('customers')->where('id', $customer)->exists(), 404);
        abort_unless(DB::table('products')->where('id', $product)->exists(), 404);

        $priceExpression = 'COALESCE(prod_sales.applied_unit_price, prod_sales.price)';
        $rows = DB::table('prod_sales')
            ->join('sales_invoices as sales', 'sales.id', '=', 'prod_sales.sales_invoice_id')
            ->join('product_batches as batches', 'batches.id', '=', 'prod_sales.batch_id')
            ->where('sales.customer_id', $customer)
            ->where('batches.product_id', $product)
            ->whereNull('sales.deleted_at')
            ->when(
                array_key_exists('unit_id', $validated) && $validated['unit_id'] !== null,
                fn ($query) => $query->where('prod_sales.transaction_unit_id', $validated['unit_id']),
                fn ($query) => $query->whereNull('prod_sales.transaction_unit_id')
            )
            ->selectRaw("sales.id as sale_id, sales.date, $priceExpression as price, MAX(prod_sales.id) as latest_line_id")
            ->groupBy('sales.id', 'sales.date', 'prod_sales.transaction_unit_id', DB::raw($priceExpression))
            ->orderByDesc('sales.date')
            ->orderByDesc('latest_line_id')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'price' => round((float) $row->price, 2),
                'date' => $row->date,
                'sale_id' => (int) $row->sale_id,
            ]);

        if ($rows->isEmpty()) {
            return $this->success(['available' => false, 'last_price' => null, 'min_price' => null, 'max_price' => null, 'recent_prices' => []]);
        }
        $prices = $rows->pluck('price');
        return $this->success([
            'available' => true,
            'last_price' => $rows->first()['price'],
            'min_price' => round((float) $prices->min(), 2),
            'max_price' => round((float) $prices->max(), 2),
            'recent_prices' => $rows->values(),
        ]);
    }

    private function projectSensitiveFields(mixed $value, $user): mixed
    {
        if ($user?->hasPermission('reports.view_profit')) return $value;
        $sensitive = ['profit', 'cost_price', 'cogs', 'gross_profit', 'profit_margin', 'markup'];
        if ($value instanceof \Illuminate\Support\Collection) {
            return $value->map(fn ($item) => $this->projectSensitiveFields($item, $user));
        } elseif (is_array($value)) {
            foreach ($sensitive as $key) unset($value[$key]);
            foreach ($value as $key => $item) $value[$key] = $this->projectSensitiveFields($item, $user);
        } elseif (is_object($value)) {
            foreach ($sensitive as $key) unset($value->{$key});
            foreach (get_object_vars($value) as $key => $item) $value->{$key} = $this->projectSensitiveFields($item, $user);
        }
        return $value;
    }


}
