<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    protected $fillable = [
        'voucher_id',
        'accounting_period_id',
        'entry_number',
        'entry_date',
        'description',
        'status',
        'total_debit',
        'total_credit',
        'created_by',
        'posted_at',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'posted_at' => 'datetime',
        'total_debit' => 'decimal:2',
        'total_credit' => 'decimal:2',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'journal_entry_id');
    }

    public function refreshTotals(): self
    {
        $this->total_debit = (float) $this->lines()->where('side', 'debit')->sum('amount');
        $this->total_credit = (float) $this->lines()->where('side', 'credit')->sum('amount');
        $this->save();

        return $this;
    }

    public function isBalanced(): bool
    {
        $this->refreshTotals();

        return round((float) $this->total_debit, 2) === round((float) $this->total_credit, 2)
            && round((float) $this->total_debit, 2) > 0;
    }
}
