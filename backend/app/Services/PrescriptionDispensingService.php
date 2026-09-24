<?php

namespace App\Services;

use App\Exceptions\PrescriptionDispensingException;
use App\Models\Prescription;
use App\Models\PrescriptionDispenseAllocation;
use App\Models\PrescriptionItem;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PrescriptionDispensingService
{
    public function dispense(int $prescriptionId, int $productId, int $quantity, User $user): array
    {
        return DB::transaction(function () use ($prescriptionId, $productId, $quantity, $user) {
            $prescription = Prescription::query()->lockForUpdate()->findOrFail($prescriptionId);

            if ($prescription->status !== 'active') {
                throw new PrescriptionDispensingException('Prescription is not active.');
            }
            if ($prescription->expires_at !== null && $prescription->expires_at->isBefore(now()->startOfDay())) {
                throw new PrescriptionDispensingException('Prescription is expired.');
            }

            $item = PrescriptionItem::query()
                ->where('prescription_id', $prescription->id)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();
            if (!$item) {
                throw new PrescriptionDispensingException('The product does not belong to this prescription.');
            }

            $remainingAllowed = (int) $item->allowed_quantity - (int) $item->dispensed_quantity;
            if ($quantity > $remainingAllowed) {
                throw new PrescriptionDispensingException('Dispensing quantity exceeds remaining prescription quantity.');
            }

            $batches = ProductBatch::query()
                ->where('product_id', $productId)
                ->availableForSale()
                ->lockForUpdate()
                ->get();
            if ($batches->sum('quantity') < $quantity) {
                throw new PrescriptionDispensingException('Insufficient sellable stock for prescription dispensing.');
            }

            $needed = $quantity;
            $allocations = [];
            foreach ($batches as $batch) {
                if ($needed === 0) break;
                $allocated = min($needed, (int) $batch->quantity);
                $batch->applyPrescriptionDispense($allocated, $prescription->id, $user->id);

                $history = PrescriptionDispenseAllocation::query()->create([
                    'prescription_id' => $prescription->id,
                    'prescription_item_id' => $item->id,
                    'product_id' => $productId,
                    'batch_id' => $batch->id,
                    'quantity' => $allocated,
                    'user_id' => $user->id,
                    'created_at' => now(),
                ]);
                $allocations[] = [
                    'id' => $history->id,
                    'batch_id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'expiry_date' => $batch->expiry_date?->format('Y-m-d'),
                    'quantity' => $allocated,
                ];
                $needed -= $allocated;
            }

            $item->increment('dispensed_quantity', $quantity);
            $item->refresh();
            $allFulfilled = !PrescriptionItem::query()
                ->where('prescription_id', $prescription->id)
                ->whereColumn('dispensed_quantity', '<', 'allowed_quantity')
                ->exists();
            if ($allFulfilled) {
                $prescription->update(['status' => 'fulfilled']);
            }

            return [
                'prescription_id' => $prescription->id,
                'product_id' => $productId,
                'dispensed_quantity' => (int) $item->dispensed_quantity,
                'remaining_quantity' => (int) $item->allowed_quantity - (int) $item->dispensed_quantity,
                'status' => $prescription->fresh()->status,
                'allocations' => $allocations,
            ];
        });
    }
}
