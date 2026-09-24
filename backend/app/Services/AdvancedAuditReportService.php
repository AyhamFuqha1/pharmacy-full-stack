<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AdvancedAuditReportService
{
    public function generate(array $filters): array
    {
        $base = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->select('audit_logs.*', 'users.name as actor_name', 'users.email as actor_email');
        $this->applyAuditFilters($base, $filters);

        $perPage = max(1, min((int) ($filters['per_page'] ?? 25), 100));
        $logs = (clone $base)->orderByDesc('audit_logs.created_at')->paginate($perPage);
        $logs->through(function ($log) {
            $log->actor = $log->user_id ? ['id' => $log->user_id, 'name' => $log->actor_name, 'email' => $log->actor_email] : null;
            $log->entity_type = $log->model_name ?: $log->table_name;
            $log->entity_label = str(class_basename($log->entity_type))->headline()->toString();
            $log->timestamp = $log->created_at;
            $log->old_values = $this->safeValues($log->old_values ?: $log->old_data);
            $log->new_values = $this->safeValues($log->new_values ?: $log->new_data);
            unset($log->actor_name, $log->actor_email, $log->old_data, $log->new_data);
            return $log;
        });

        $byAction = (clone $base)
            ->select('audit_logs.action_type', DB::raw('COUNT(*) as total'))
            ->groupBy('audit_logs.action_type')
            ->orderByDesc('total')
            ->get();

        $byTable = (clone $base)
            ->select('audit_logs.table_name', DB::raw('COUNT(*) as total'))
            ->groupBy('audit_logs.table_name')
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
            $query->whereDate('audit_logs.created_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query->whereDate('audit_logs.created_at', '<=', $filters['to']);
        }
        if (!empty($filters['user_id'])) {
            $query->where('audit_logs.user_id', (int) $filters['user_id']);
        }
        if (!empty($filters['table_name'])) {
            $query->where('audit_logs.table_name', $filters['table_name']);
        }
        if (!empty($filters['action_type'])) {
            $query->where('audit_logs.action_type', $filters['action_type']);
        }
    }

    private function safeValues($json): ?array
    {
        if (!$json) return null;
        $values = is_array($json) ? $json : json_decode($json, true);
        if (!is_array($values)) return null;
        return collect($values)->except(['password', 'remember_token', 'token', 'api_token', 'access_token'])->all();
    }
}
