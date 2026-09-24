<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class InventoryExpansionService
{
    public function __construct(private DocumentNumberService $numbers) {}

    public function createOpening(array $d, int $user): int
    {
        return DB::transaction(function () use ($d, $user) {
            $id = DB::table('opening_balance_sessions')->insertGetId(['document_number' => $this->numbers->next('opening', 'OPN'), 'branch_id' => $d['branch_id'] ?? null, 'effective_date' => $d['effective_date'], 'status' => 'draft', 'notes' => $d['notes'] ?? null, 'created_by' => $user, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($d['lines'] as $line) {
                if (($line['type'] ?? null) === 'inventory') {
                    $snap = $this->unitSnapshot((int) $line['product_id'], (float) $line['quantity'], $line['unit_id'] ?? null);
                    $line = array_merge($line, $snap, ['quantity' => $snap['normalized_base_quantity'], 'transaction_quantity' => $snap['entered_quantity'], 'transaction_unit_id' => $snap['entered_unit_id'], 'conversion_factor' => $snap['conversion_factor_snapshot'], 'base_quantity' => $snap['normalized_base_quantity']]);
                }unset($line['unit_id']);
                DB::table('opening_balance_lines')->insert(array_merge($line, ['session_id' => $id, 'metadata' => isset($line['metadata']) ? json_encode($line['metadata']) : null, 'created_at' => now(), 'updated_at' => now()]));
            }

return $id;
        });
    }

    public function validateOpening(int $id): void
    {
        DB::transaction(function () use ($id) {
            $s = DB::table('opening_balance_sessions')->where('id', $id)->lockForUpdate()->first();
            if (! $s || $s->status !== 'draft') {
                throw new RuntimeException('Only draft opening sessions can be validated.');
            }if (! DB::table('opening_balance_lines')->where('session_id', $id)->exists()) {
                throw ValidationException::withMessages(['lines' => 'At least one line is required.']);
            }DB::table('opening_balance_sessions')->where('id', $id)->update(['status' => 'validated', 'validated_at' => now(), 'updated_at' => now()]);
        });
    }

    public function postOpening(int $id, int $user): void
    {
        DB::transaction(function () use ($id, $user) {
            $s = DB::table('opening_balance_sessions')->where('id', $id)->lockForUpdate()->first();
            if (! $s || $s->status !== 'validated') {
                throw new RuntimeException('Only validated opening sessions can be posted.');
            }$lines = DB::table('opening_balance_lines')->where('session_id', $id)->get();
            $journal = [];
            $debit = 0;
            $credit = 0;
            foreach ($lines as $l) {
                $amount = (float) ($l->amount ?? 0);
                if ($l->type === 'inventory') {
                    $batch = DB::table('product_batches')->where(['product_id' => $l->product_id, 'batch_number' => $l->batch_number, 'branch_id' => $s->branch_id])->when($l->expiry_date, fn ($query, $date) => $query->whereDate('expiry_date', $date), fn ($query) => $query->whereNull('expiry_date'))->lockForUpdate()->first();
                    $bid = $batch?->id ?? DB::table('product_batches')->insertGetId(['product_id' => $l->product_id, 'branch_id' => $s->branch_id, 'batch_number' => $l->batch_number, 'expiry_date' => $l->expiry_date, 'purchase_price' => $l->unit_cost, 'sale_price' => $l->sale_price ?? $l->unit_cost, 'quantity' => 0, 'inventory_state' => 'available', 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('product_batches')->where('id', $bid)->increment('quantity', $l->quantity);
                    DB::table('opening_balance_lines')->where('id', $l->id)->update(['batch_id' => $bid]);
                    DB::table('stock')->insert(['batch_id' => $bid, 'branch_id' => $s->branch_id, 'quantity' => $l->quantity, 'type' => 'OPENING_STOCK_IN', 'reference_type' => 'opening_balance', 'reference_id' => $id, 'user_id' => $user, 'unit_cost' => $l->unit_cost, 'disposition' => 'commercial', 'created_at' => now()]);
                    $amount = (float) $l->quantity * (float) $l->unit_cost;
                    $journal[] = ['1400', 'debit', $amount];
                    $debit += $amount;
                } elseif (in_array($l->type, ['customer_ar', 'cash', 'bank', 'incoming_cheque'], true)) {
                    $code = ['customer_ar' => '1300', 'cash' => '1100', 'bank' => '1200', 'incoming_cheque' => '1500'][$l->type];
                    $journal[] = [$code, 'debit', $amount];
                    $debit += $amount;
                } elseif (in_array($l->type, ['supplier_ap', 'outgoing_cheque'], true)) {
                    $code = $l->type === 'supplier_ap' ? '2100' : '2200';
                    $journal[] = [$code, 'credit', $amount];
                    $credit += $amount;
                } else {
                    throw ValidationException::withMessages(['type' => 'Unsupported opening line type.']);
                }
            }if ($debit > $credit) {
                $journal[] = ['3000', 'credit', $debit - $credit];
            } elseif ($credit > $debit) {
                $journal[] = ['3000', 'debit', $credit - $debit];
            }$this->journal('opening_balance', $id, $s->effective_date, $user, 'Opening balances', $journal);
            DB::table('opening_balance_sessions')->where('id', $id)->update(['status' => 'posted', 'posted_by' => $user, 'posted_at' => now(), 'updated_at' => now()]);
        });
    }

    public function createCount(array $d, int $user): int
    {
        return DB::transaction(function () use ($d, $user) {
            $id = DB::table('stock_counts')->insertGetId(['document_number' => $this->numbers->next('stock_count', 'CNT'), 'branch_id' => $d['branch_id'] ?? null, 'scope' => $d['scope'], 'status' => 'draft', 'created_by' => $user, 'snapshot_at' => now(), 'notes' => $d['notes'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            $q = DB::table('product_batches')->when($d['branch_id'] ?? null, fn ($q) => $q->where('branch_id', $d['branch_id']))->when(($d['scope'] === 'selected'), fn ($q) => $q->whereIn('product_id', $d['product_ids']));
            foreach ($q->get() as $b) {
                DB::table('stock_count_lines')->insert(['stock_count_id' => $id, 'product_id' => $b->product_id, 'batch_id' => $b->id, 'system_quantity' => $b->quantity]);
            }

return $id;
        });
    }

    public function postCount(int $id, array $counts, int $user): void
    {
        DB::transaction(function () use ($id, $counts, $user) {
            $c = DB::table('stock_counts')->where('id', $id)->lockForUpdate()->first();
            if (! $c) {
                throw ValidationException::withMessages(['stock_count' => __('api.stock_count_not_found')]);
            }
            if ($c->status === 'posted') {
                throw ValidationException::withMessages(['stock_count' => __('api.stock_count_already_posted')]);
            }
            $actorBranch = DB::table('users')->where('id', $user)->value('branch_id');
            if ($actorBranch !== null && $c->branch_id !== null && (int) $actorBranch !== (int) $c->branch_id) {
                throw ValidationException::withMessages(['branch_id' => __('api.stock_count_branch_mismatch')]);
            }
            foreach ($counts as $x) {
                $line = DB::table('stock_count_lines')->where(['stock_count_id' => $id, 'batch_id' => $x['batch_id']])->lockForUpdate()->first();
                if (! $line) {
                    throw ValidationException::withMessages(['lines' => __('api.batch_outside_count_snapshot')]);
                }
                $batch = DB::table('product_batches')->where('id', $line->batch_id)->lockForUpdate()->first();
                if (! $batch || (int) $batch->product_id !== (int) $line->product_id) {
                    throw ValidationException::withMessages(['batch_id' => __('api.batch_product_mismatch')]);
                }
                if ($c->branch_id !== null && ! app(BatchBranchService::class)->owns($batch, (int) $c->branch_id)) {
                    throw ValidationException::withMessages(['batch_id' => __('api.batch_branch_mismatch')]);
                }
                if ($actorBranch !== null && ! app(BatchBranchService::class)->owns($batch, (int) $actorBranch)) {
                    throw ValidationException::withMessages(['batch_id' => __('api.batch_branch_mismatch')]);
                }
                $snap = $this->unitSnapshot((int) $line->product_id, (float) $x['counted_quantity'], $x['unit_id'] ?? null);
                $counted = $snap['normalized_base_quantity'];
                $diff = $counted - $line->system_quantity;
                DB::table('stock_count_lines')->where('id', $line->id)->update(array_merge(['counted_quantity' => $counted, 'difference' => $diff, 'reason' => $x['reason'] ?? null, 'note' => $x['note'] ?? null], $snap));
                if ($diff) {
                    $movementBranch = app(BatchBranchService::class)->resolve(
                        $c->branch_id !== null ? (int) $c->branch_id : ($batch->branch_id !== null ? (int) $batch->branch_id : null),
                        $actorBranch !== null ? (int) $actorBranch : null,
                    );
                    $this->applyMovement($line->batch_id, $diff, $movementBranch, 'stock_count', $id, $user, 'ADJUSTMENT');
                }
            }
            DB::table('stock_counts')->where('id', $id)->update(['status' => 'posted', 'posted_by' => $user, 'posted_at' => now(), 'updated_at' => now()]);
        });
    }

    public function adjust(array $d, int $user): int
    {
        return DB::transaction(function () use ($d, $user) {
            $b = DB::table('product_batches')->where('id', $d['batch_id'])->lockForUpdate()->first();
            if (! $b || $b->product_id != $d['product_id']) {
                throw ValidationException::withMessages(['batch_id' => __('api.batch_product_mismatch')]);
            }
            if (array_key_exists('branch_id', $d) && $d['branch_id'] !== null && ! app(BatchBranchService::class)->owns($b, (int) $d['branch_id'])) {
                throw ValidationException::withMessages(['batch_id' => __('api.batch_branch_mismatch')]);
            }$snap = $this->unitSnapshot((int) $d['product_id'], (float) $d['quantity'], $d['unit_id'] ?? null);
            $qty = $snap['normalized_base_quantity'];
            $id = DB::table('stock_adjustments')->insertGetId(array_merge(['document_number' => $this->numbers->next('adjustment', 'ADJ'), 'branch_id' => $d['branch_id'] ?? $b->branch_id, 'product_id' => $d['product_id'], 'batch_id' => $b->id, 'quantity' => $qty, 'reason' => $d['reason'], 'note' => $d['note'] ?? null, 'unit_cost' => $b->purchase_price, 'user_id' => $user, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now()], $snap));
            $this->applyMovement($b->id, $qty, $d['branch_id'] ?? $b->branch_id, 'stock_adjustment', $id, $user, 'ADJUSTMENT');

            return $id;
        });
    }

    public function createTransfer(array $d, int $user): int
    {
        return DB::transaction(function () use ($d, $user) {
            $this->assertTransferBranchAccess((int) $d['source_branch_id'], $user);
            if ($d['source_branch_id'] === $d['destination_branch_id']) {
                throw ValidationException::withMessages(['destination_branch_id' => __('transfer.same_branch')]);
            }$id = DB::table('stock_transfers')->insertGetId(['document_number' => $this->numbers->next('transfer', 'TRF'), 'source_branch_id' => $d['source_branch_id'], 'destination_branch_id' => $d['destination_branch_id'], 'status' => 'draft', 'created_by' => $user, 'notes' => $d['notes'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($d['lines'] as $l) {
                $b = DB::table('product_batches')->where('id', $l['batch_id'])->first();
                if (! $b || ! app(BatchBranchService::class)->owns($b, (int) $d['source_branch_id'])) {
                    throw ValidationException::withMessages(['batch_id' => __('transfer.batch_source_mismatch')]);
                }$snap = $this->unitSnapshot((int) $b->product_id, (float) $l['quantity'], $l['unit_id'] ?? null);
                if ((float) $b->quantity < (float) $snap['normalized_base_quantity']) {
                    throw ValidationException::withMessages(['quantity' => __('transfer.insufficient_source_stock')]);
                }
                DB::table('stock_transfer_lines')->insert(array_merge(['transfer_id' => $id, 'product_id' => $b->product_id, 'source_batch_id' => $b->id, 'quantity' => $snap['normalized_base_quantity'], 'transaction_quantity' => $snap['entered_quantity'], 'transaction_unit_id' => $snap['entered_unit_id'], 'conversion_factor' => $snap['conversion_factor_snapshot'], 'unit_cost' => $b->purchase_price, 'batch_number' => $b->batch_number, 'expiry_date' => $b->expiry_date], $snap));
            }

return $id;
        });
    }

    public function sendTransfer(int $id, int $user): void
    {
        DB::transaction(function () use ($id, $user) {
            $t = DB::table('stock_transfers')->where('id', $id)->lockForUpdate()->first();
            if (! $t || $t->status !== 'draft') {
                abort(409, 'Only draft transfers can be sent.');
            }
            $this->assertTransferBranchAccess((int) $t->source_branch_id, $user);
            foreach (DB::table('stock_transfer_lines')->where('transfer_id', $id)->get() as $l) {
                $b = DB::table('product_batches')->where('id', $l->source_batch_id)->lockForUpdate()->first();
                if (! $b || ! app(BatchBranchService::class)->owns($b, (int) $t->source_branch_id)) {
                    throw ValidationException::withMessages(['batch_id' => __('transfer.batch_source_mismatch')]);
                }
                if ((int) $b->product_id !== (int) $l->product_id) {
                    throw ValidationException::withMessages(['batch_id' => __('api.batch_product_mismatch')]);
                }
                if ((float) $b->quantity < (float) $l->quantity) {
                    throw ValidationException::withMessages(['quantity' => __('transfer.insufficient_source_stock')]);
                }DB::table('product_batches')->where('id', $b->id)->decrement('quantity', $l->quantity);
                DB::table('stock')->insert(['batch_id' => $b->id, 'branch_id' => $t->source_branch_id, 'quantity' => $l->quantity, 'type' => 'TRANSFER_OUT', 'reference_type' => 'stock_transfer', 'reference_id' => $id, 'user_id' => $user, 'unit_cost' => $l->unit_cost, 'disposition' => 'in_transit', 'created_at' => now()]);
            }DB::table('stock_transfers')->where('id', $id)->update(['status' => 'in_transit', 'sent_by' => $user, 'sent_at' => now(), 'updated_at' => now()]);
        });
    }

    public function receiveTransfer(int $id, int $user): void
    {
        DB::transaction(function () use ($id, $user) {
            $t = DB::table('stock_transfers')->where('id', $id)->lockForUpdate()->first();
            if (! $t || $t->status !== 'in_transit') {
                abort(409, 'Only in-transit transfers can be received.');
            }foreach (DB::table('stock_transfer_lines')->where('transfer_id', $id)->get() as $l) {
                $b = DB::table('product_batches')->where(['product_id' => $l->product_id, 'branch_id' => $t->destination_branch_id, 'batch_number' => $l->batch_number])->when($l->expiry_date, fn ($query, $date) => $query->whereDate('expiry_date', $date), fn ($query) => $query->whereNull('expiry_date'))->lockForUpdate()->first();
                $bid = $b?->id ?? DB::table('product_batches')->insertGetId(['product_id' => $l->product_id, 'branch_id' => $t->destination_branch_id, 'batch_number' => $l->batch_number, 'expiry_date' => $l->expiry_date, 'purchase_price' => $l->unit_cost, 'sale_price' => DB::table('product_batches')->where('id', $l->source_batch_id)->value('sale_price'), 'quantity' => 0, 'inventory_state' => 'available', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('product_batches')->where('id', $bid)->increment('quantity', $l->quantity);
                DB::table('stock_transfer_lines')->where('id', $l->id)->update(['destination_batch_id' => $bid]);
                DB::table('stock')->insert(['batch_id' => $bid, 'branch_id' => $t->destination_branch_id, 'quantity' => $l->quantity, 'type' => 'TRANSFER_IN', 'reference_type' => 'stock_transfer', 'reference_id' => $id, 'user_id' => $user, 'unit_cost' => $l->unit_cost, 'disposition' => 'commercial', 'created_at' => now()]);
            }DB::table('stock_transfers')->where('id', $id)->update(['status' => 'received', 'received_by' => $user, 'received_at' => now(), 'updated_at' => now()]);
        });
    }

    public function cancelTransfer(int $id): void
    {
        DB::transaction(function () use ($id) {
            $t = DB::table('stock_transfers')->where('id', $id)->lockForUpdate()->first();
            if (! $t || $t->status !== 'draft') {
                abort(409, 'Only draft transfers can be cancelled.');
            }DB::table('stock_transfers')->where('id', $id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);
        });
    }

    private function applyMovement(int $batch, float $diff, $branch, string $ref, int $refId, int $user, string $prefix): void
    {
        $b = DB::table('product_batches')->where('id', $batch)->lockForUpdate()->first();
        if ($b->quantity + $diff < 0) {
            throw ValidationException::withMessages(['quantity' => __('api.adjustment_exceeds_available_stock')]);
        }DB::table('product_batches')->where('id', $batch)->update(['quantity' => $b->quantity + $diff, 'updated_at' => now()]);
        DB::table('stock')->insert(['batch_id' => $batch, 'branch_id' => $branch, 'quantity' => abs($diff), 'type' => $prefix.($diff > 0 ? '_IN' : '_OUT'), 'reference_type' => $ref, 'reference_id' => $refId, 'user_id' => $user, 'unit_cost' => $b->purchase_price, 'disposition' => 'commercial', 'created_at' => now()]);
        $amount = abs($diff) * (float) $b->purchase_price;
        $this->journal($ref, $refId, app(BusinessTimeService::class)->date(), $user, 'Inventory adjustment', $diff > 0 ? [['1400', 'debit', $amount], ['5300', 'credit', $amount]] : [['5300', 'debit', $amount], ['1400', 'credit', $amount]]);
    }

    private function assertTransferBranchAccess(int $sourceBranch, int $userId): void
    {
        $user = DB::table('users')->where('id', $userId)->first(['role', 'branch_id']);
        if ($user && (int) $user->role !== 1 && $user->branch_id !== null && (int) $user->branch_id !== $sourceBranch) {
            throw ValidationException::withMessages(['source_branch_id' => __('transfer.source_branch_forbidden')]);
        }
    }

    private function unitSnapshot(int $product, float $entered, $unitId): array
    {
        $base = (int) DB::table('products')->where('id', $product)->value('base_unit_id');
        $unit = $unitId ? (int) $unitId : $base;
        $factor = 1.0;
        if ($unit && $unit !== $base) {
            $factor = (float) (DB::table('product_units')->where(['product_id' => $product, 'unit_id' => $unit, 'is_active' => 1])->value('base_quantity') ?? 0);
            if ($factor <= 0) {
                throw ValidationException::withMessages(['unit_id' => 'Unit is not configured and active for this product.']);
            }
        }

return ['entered_quantity' => $entered, 'entered_unit_id' => $unit ?: null, 'conversion_factor_snapshot' => $factor, 'normalized_base_quantity' => round($entered * $factor, 3)];
    }

    private function journal(string $type, int $id, string $date, int $user, string $description, array $lines): void
    {
        $period = AccountingPeriod::query()->where('start_date', '<=', $date)->where('end_date', '>=', $date)->first();
        if (! $period || $period->status !== 'open') {
            throw new RuntimeException('No open accounting period covers this date.');
        }$voucher = DB::table('vouchers')->insertGetId(['voucher_number' => $this->numbers->next('voucher', 'VCH'), 'voucher_type' => 'journal_adjustment', 'source_type' => $type, 'source_id' => $id, 'status' => 'posted', 'accounting_period_id' => $period->id, 'user_id' => $user, 'posted_at' => now(), 'notes' => $description, 'created_at' => now(), 'updated_at' => now()]);
        $total = collect($lines)->sum(fn ($l) => $l[1] === 'debit' ? $l[2] : 0);
        $entry = DB::table('journal_entries')->insertGetId(['entry_number' => $this->numbers->next('journal', 'JRN'), 'voucher_id' => $voucher, 'accounting_period_id' => $period->id, 'entry_date' => $date, 'description' => $description, 'status' => 'posted', 'total_debit' => $total, 'total_credit' => $total, 'created_by' => $user, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        foreach ($lines as [$code,$side,$amount]) {
            if ($amount > 0) {
                DB::table('journal_entry_lines')->insert(['journal_entry_id' => $entry, 'account_id' => DB::table('accounts')->where('code',$code)->value('id'), 'side' => $side, 'amount' => $amount, 'description' => $description, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
