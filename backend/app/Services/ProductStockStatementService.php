<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductStockStatementService
{
    public const FILTER_RULES = [
        'from' => 'nullable|date',
        'to' => 'nullable|date|after_or_equal:from',
        'branch_id' => 'nullable|exists:branches,id',
        'batch_id' => 'nullable|exists:product_batches,id',
        'movement_type' => 'nullable|string|max:50',
    ];

    public function query(Request $request, int $productId, bool $all = false): array
    {
        abort_unless(DB::table('products')->where('id', $productId)->exists(), 404);

        $filters = $request->validate($all
            ? self::FILTER_RULES
            : array_merge(self::FILTER_RULES, [
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]));

        if (isset($filters['batch_id'])) {
            abort_unless(DB::table('product_batches')->where('id', $filters['batch_id'])->where('product_id', $productId)->exists(), 422, 'The selected batch does not belong to the product.');
        }

        $baseline = $this->baseQuery($productId, $filters, false)
            ->where('s.type', 'OPENING_STOCK_IN')
            ->oldest('s.created_at')->oldest('s.id')->first(['s.created_at']);

        $openingBoundary = isset($filters['from'])
            ? app(BusinessTimeService::class)->utcStart($filters['from'])
            : $baseline?->created_at;
        $opening = $openingBoundary
            ? $this->signedSum($this->baseQuery($productId, $filters)->where('s.created_at', '<', $openingBoundary))
            : null;

        $rowsQuery = $this->baseQuery($productId, $filters);
        app(BusinessTimeService::class)->applyUtcDateFilter($rowsQuery, 's.created_at', $filters['from'] ?? null, $filters['to'] ?? null);
        $periodDelta = $this->signedSum(clone $rowsQuery);
        $closing = $opening === null ? null : $opening + $periodDelta;
        $decorate = function ($row) use ($opening, $productId, $filters) {
            $row->signed_quantity = $this->signed($row);
            if ($opening !== null) {
                $throughRow = $this->baseQuery($productId, $filters);
                app(BusinessTimeService::class)->applyUtcDateFilter($throughRow, 's.created_at', $filters['from'] ?? null, $filters['to'] ?? null);
                $throughRow->where(fn ($q) => $q->where('s.created_at', '<', $row->created_at)->orWhere(fn ($same) => $same->where('s.created_at', $row->created_at)->where('s.id', '<=', $row->id)));
                $row->running_quantity = round($opening + $this->signedSum($throughRow), 3);
            } else {
                $row->running_quantity = null;
            }
            $row->source_label = app(DocumentReferenceService::class)->label($row->source_type, $row->source_id ? (int) $row->source_id : null);
            return $row;
        };

        // Product 360 follows the authoritative stock-movement report convention.
        if ($all) {
            $movements = $rowsQuery->orderByDesc('s.created_at')->orderByDesc('s.id')->get()->map($decorate);
        } else {
            $movements = $rowsQuery->orderByDesc('s.created_at')->orderByDesc('s.id')->paginate($request->integer('per_page', 25));
            $movements->setCollection($movements->getCollection()->map($decorate));
        }

        return [
            'running_balance_available' => $baseline !== null,
            'running_balance_reason' => $baseline ? null : 'No authoritative opening-stock baseline exists.',
            'opening_quantity' => $opening === null ? null : round((float) $opening, 3),
            'closing_quantity' => $closing === null ? null : round($closing, 3),
            'movements' => $movements,
        ];
    }

    private function baseQuery(int $productId, array $filters, bool $movementType = true)
    {
        $canViewCost = request()->user()?->hasPermission('reports.view_cost') ?? false;
        $query = DB::table('stock as s')
            ->join('product_batches as b', 'b.id', '=', 's.batch_id')
            ->join('products as p', 'p.id', '=', 'b.product_id')
            ->leftJoin('branches as br', fn ($join) => $join->on('br.id', '=', DB::raw('COALESCE(s.branch_id, b.branch_id)')))
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('p.id', $productId)
            ->when($filters['branch_id'] ?? null, function ($q, $id) {
                $main = DB::table('branches')->where('code', 'MAIN')->where('is_active', true)->value('id');
                $q->where(function ($branch) use ($id, $main) {
                    $branch->where('s.branch_id', $id);
                    if ((int) $id === (int) $main) {
                        $branch->orWhere(fn ($legacy) => $legacy->whereNull('s.branch_id')->where(fn ($batch) => $batch->whereNull('b.branch_id')->orWhere('b.branch_id', $id)));
                    }
                });
            })
            ->when($filters['batch_id'] ?? null, fn ($q, $id) => $q->where('s.batch_id', $id))
            ->when($movementType ? ($filters['movement_type'] ?? null) : null, fn ($q, $type) => $q->where('s.type', $type))
            ->select([
                's.id', 's.created_at', 's.type as movement_type', 's.quantity', 's.branch_id',
                's.reference_type as source_type', 's.reference_id as source_id', 's.user_id',
                'u.name as user_name', 'p.id as product_id', 'p.name as product_name', 'p.barcode as product_barcode',
                'b.id as batch_id', 'b.batch_number', 'b.expiry_date as batch_expiry',
                'br.code as branch_code', 'br.name as branch_name',
            ]);

        if ($canViewCost) {
            $query->selectRaw('COALESCE(s.unit_cost, b.purchase_price) as cost_basis');
        }

        return $query;
    }

    private function signed(object $row): float
    {
        $type = strtoupper($row->movement_type);
        return str_contains($type, 'OUT') || in_array($type, ['SALE', 'PURCHASE_RETURN'], true)
            ? -(float) $row->quantity
            : (float) $row->quantity;
    }

    private function signedSum($query): float
    {
        return (float) $query->select([])->selectRaw("COALESCE(SUM(CASE WHEN UPPER(s.type) LIKE '%OUT%' OR UPPER(s.type) IN ('SALE', 'PURCHASE_RETURN') THEN -s.quantity ELSE s.quantity END), 0) as signed_total")->value('signed_total');
    }

}
