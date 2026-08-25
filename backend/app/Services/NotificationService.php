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
            $alerts[] = ['type' => 'low_stock', 'text' => "{$batch->name} batch {$batch->batch_number} is out of stock."];
        }
        foreach ($batches->filter(fn ($batch) => Carbon::parse($batch->expiry_date)->betweenIncluded($today, $today->copy()->addDays(30))) as $batch) {
            $alerts[] = ['type' => 'expiring_batch', 'text' => "{$batch->name} batch {$batch->batch_number} expires on {$batch->expiry_date}."];
        }
        foreach (app(AccountingReportService::class)->customerStatementIdsWithBalances() as $balance) {
            $alerts[] = ['type' => 'customer_due', 'text' => "Customer {$balance['name']} has a balance of {$balance['balance']}."];
        }
        foreach (app(AccountingReportService::class)->supplierStatementIdsWithBalances() as $balance) {
            $alerts[] = ['type' => 'supplier_due', 'text' => "Supplier {$balance['name']} has a balance of {$balance['balance']}."];
        }

        foreach ($alerts as $alert) {
            DB::table('notifications')->updateOrInsert(
                ['user_id' => $userId, 'type' => $alert['type'], 'text' => $alert['text']],
                ['updated_at' => now(), 'created_at' => now()]
            );
        }

        return DB::table('notifications')->where('user_id', $userId)->latest()->get()->all();
    }
}
