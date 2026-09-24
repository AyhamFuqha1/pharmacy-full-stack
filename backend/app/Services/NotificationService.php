<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    public function forUser(int $userId): array
    {
        $today = Carbon::today();
        $alerts = [];
        $batches = DB::table('product_batches')->join('products', 'products.id', '=', 'product_batches.product_id')
            ->whereNull('product_batches.deleted_at')->get(['product_batches.*', 'products.name']);

        foreach ($batches->filter(fn ($batch) => $batch->quantity <= 0) as $batch) {
            $alerts[] = ['type' => 'low_stock', 'entity_type' => 'product_batch', 'entity_id' => $batch->id, 'text' => "{$batch->name} batch {$batch->batch_number} is out of stock."];
        }
        foreach ($batches->filter(fn ($batch) => $batch->expiry_date !== null && Carbon::parse($batch->expiry_date)->betweenIncluded($today, $today->copy()->addDays(30))) as $batch) {
            $alerts[] = ['type' => 'expiring_batch', 'entity_type' => 'product_batch', 'entity_id' => $batch->id, 'text' => "{$batch->name} batch {$batch->batch_number} expires on {$batch->expiry_date}."];
        }
        foreach (app(AccountingReportService::class)->customerStatementIdsWithBalances() as $balance) {
            $alerts[] = ['type' => 'customer_due', 'entity_type' => 'customer', 'entity_id' => $balance['id'] ?? null, 'text' => "Customer {$balance['name']} has a balance of {$balance['balance']}."];
        }
        foreach (app(AccountingReportService::class)->supplierStatementIdsWithBalances() as $balance) {
            $alerts[] = ['type' => 'supplier_due', 'entity_type' => 'provider', 'entity_id' => $balance['id'] ?? null, 'text' => "Supplier {$balance['name']} has a balance of {$balance['balance']}."];
        }

        return collect($alerts)->unique(fn ($alert) => $alert['type'].':'.$alert['entity_type'].':'.$alert['entity_id'])
            ->map(fn ($alert) => (object) array_merge($alert, ['semantics' => 'current_actionable', 'generated_at' => now()->toIso8601String()]))
            ->values()->all();
    }
}
