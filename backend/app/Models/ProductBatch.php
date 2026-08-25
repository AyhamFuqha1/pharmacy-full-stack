<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProductBatch extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'batch_number',
        'expiry_date',
        'quantity',
        'purchase_price',
        'sale_price',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'purchase_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'expiry_date' => 'date',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(Stock::class, 'batch_id');
    }

    public function scopeAvailableForSale($query)
    {
        return $query
            ->whereNull('deleted_at')
            ->where('quantity', '>', 0)
            ->whereDate('expiry_date', '>=', now()->toDateString())
            ->orderBy('expiry_date', 'asc')
            ->orderBy('id', 'asc');
    }

    public function isSellable(): bool
    {
        return $this->deleted_at === null
            && $this->quantity > 0
            && $this->expiry_date >= now()->toDateString();
    }

    public function applyPurchase(int $quantity, float $unitCost): self
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Purchase quantity must be greater than zero.');
        }

        if ($unitCost < 0) {
            throw new InvalidArgumentException('Purchase unit cost cannot be negative.');
        }

        $previousQuantity = (int) $this->quantity;
        $previousCost = (float) $this->purchase_price;
        $newQuantity = $previousQuantity + $quantity;

        $weightedAverage = $newQuantity > 0
            ? (($previousQuantity * $previousCost) + ($quantity * $unitCost)) / $newQuantity
            : 0;

        $this->quantity = $newQuantity;
        $this->purchase_price = round($weightedAverage, 2);
        $this->save();

        Stock::query()->create([
            'batch_id' => $this->id,
            'quantity' => $quantity,
            'type' => 'PURCHASE_IN',
            'reference_type' => 'purchase',
            'reference_id' => null,
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);

        return $this;
    }

    public function applySale(int $quantity): self
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Sale quantity must be greater than zero.');
        }

        if ($quantity > (int) $this->quantity) {
            throw new InvalidArgumentException('Insufficient stock for this batch.');
        }

        $this->quantity -= $quantity;
        $this->save();

        Stock::query()->create([
            'batch_id' => $this->id,
            'quantity' => $quantity,
            'type' => 'SALE_OUT',
            'reference_type' => 'sale',
            'reference_id' => null,
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);

        return $this;
    }

    public static function findOrCreateBatch(int $productId, string $batchNumber, string $expiryDate, float $purchasePrice, float $salePrice): self
    {
        $batch = static::query()
            ->where('product_id', $productId)
            ->where('batch_number', $batchNumber)
            ->whereDate('expiry_date', $expiryDate)
            ->first();

        if ($batch) {
            return $batch;
        }

        return static::query()->create([
            'product_id' => $productId,
            'batch_number' => $batchNumber,
            'expiry_date' => $expiryDate,
            'quantity' => 0,
            'purchase_price' => $purchasePrice,
            'sale_price' => $salePrice,
        ]);
    }
}
