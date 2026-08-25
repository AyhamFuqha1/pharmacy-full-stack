<?php

namespace App\Providers;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Voucher;
use App\Models\JournalEntry;
use App\Observers\AuditableObserver;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */

    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([Product::class, ProductBatch::class, Voucher::class, JournalEntry::class, Branch::class, Patient::class, Prescription::class, PrescriptionItem::class] as $model) {
            $model::observe(AuditableObserver::class);
        }
    }
}
