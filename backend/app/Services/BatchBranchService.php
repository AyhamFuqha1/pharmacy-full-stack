<?php

namespace App\Services;

use App\Models\ProductBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BatchBranchService
{
    public function applyScope(Builder $query, int $branchId): void
    {
        $legacyDefault = $this->legacyDefaultBranchId();
        $query->where(function (Builder $branch) use ($branchId, $legacyDefault) {
            $branch->where('product_batches.branch_id', $branchId);
            if ($legacyDefault === $branchId) {
                $branch->orWhereNull('product_batches.branch_id');
            }
        });
    }

    public function owns(object $batch, int $branchId): bool
    {
        if ($batch->branch_id !== null) {
            return (int) $batch->branch_id === $branchId;
        }

        return $this->legacyDefaultBranchId() === $branchId;
    }

    public function resolve(?int $requestedBranch, ?int $userBranch): ?int
    {
        return $requestedBranch ?: ($userBranch ?: $this->legacyDefaultBranchId());
    }

    /** @return Collection<int, float> Product ID => sellable base quantity. */
    public function sellableStockByProduct(?int $branchId = null): Collection
    {
        $query = ProductBatch::query()->availableForSale();
        if ($branchId !== null) {
            $this->applyScope($query, $branchId);
        }

        return $query->get(['product_id', 'quantity'])
            ->groupBy('product_id')
            ->map(fn (Collection $batches): float => (float) $batches->sum('quantity'));
    }

    /** @return Collection<int, array{branch_id: int, quantity: float}> */
    public function sellableStockByBranchForProduct(int $productId): Collection
    {
        $legacyDefault = $this->legacyDefaultBranchId();

        return ProductBatch::query()->availableForSale()->where('product_id', $productId)
            ->get(['branch_id', 'quantity'])
            ->groupBy(fn (ProductBatch $batch): int|string => $batch->branch_id === null
                ? ($legacyDefault ?? 'unassigned')
                : (int) $batch->branch_id)
            ->filter(fn (Collection $batches, int|string $branch): bool => is_int($branch))
            ->map(fn (Collection $batches, int|string $branch): array => [
                'branch_id' => (int) $branch,
                'quantity' => (float) $batches->sum('quantity'),
            ])->values();
    }

    private function legacyDefaultBranchId(): ?int
    {
        $id = DB::table('branches')->where('code', 'MAIN')->where('is_active', true)->value('id');
        return $id === null ? null : (int) $id;
    }
}
