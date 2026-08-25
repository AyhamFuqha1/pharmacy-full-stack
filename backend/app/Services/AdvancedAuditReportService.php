<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AdvancedAuditReportService
{
    public function generate(array $filters): array
    {
        $base = DB::table('audit_logs');
        $this->applyAuditFilters($base, $filters);

        $perPage = max(1, min((int) ($filters['per_page'] ?? 25), 100));
        $logs = (clone $base)->orderByDesc('created_at')->paginate($perPage);

        $byAction = (clone $base)
            ->select('action_type', DB::raw('COUNT(*) as total'))
            ->groupBy('action_type')
            ->orderByDesc('total')
            ->get();

        $byTable = (clone $base)
            ->select('table_name', DB::raw('COUNT(*) as total'))
            ->groupBy('table_name')
            ->orderByDesc('total')
            ->get();

        $controlledDispensing = DB::table('prescription_items as items')
            ->join('prescriptions', 'prescriptions.id', '=', 'items.prescription_id')
            ->join('products', 'products.id', '=', 'items.product_id')
            ->where('products.is_controlled', true)
            ->when(!empty($filters['branch_id']), fn ($query) => $query->where('prescriptions.branch_id', (int) $filters['branch_id']))
            ->when(!empty($filters['from']), fn ($query) => $query->whereDate('prescriptions.prescribed_at', '>=', $filters['from']))
            ->when(!empty($filters['to']), fn ($query) => $query->whereDate('prescriptions.prescribed_at', '<=', $filters['to']))
            ->selectRaw('COUNT(DISTINCT items.prescription_id) as prescription_count')
            ->selectRaw('SUM(items.allowed_quantity) as allowed_quantity')
            ->selectRaw('SUM(items.dispensed_quantity) as dispensed_quantity')
            ->first();

        return [
            'filters' => $filters,
            'audit_logs' => $logs,
            'summary' => [
                'by_action' => $byAction,
                'by_table' => $byTable,
            ],
            'controlled_medicine_dispensing' => [
                'prescription_count' => (int) ($controlledDispensing->prescription_count ?? 0),
                'allowed_quantity' => (int) ($controlledDispensing->allowed_quantity ?? 0),
                'dispensed_quantity' => (int) ($controlledDispensing->dispensed_quantity ?? 0),
            ],
        ];
    }

    private function applyAuditFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        if (!empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }
        if (!empty($filters['table_name'])) {
            $query->where('table_name', $filters['table_name']);
        }
        if (!empty($filters['action_type'])) {
            $query->where('action_type', $filters['action_type']);
        }
    }
}
