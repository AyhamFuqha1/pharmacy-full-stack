<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrescriptionDispenseAllocation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'prescription_id', 'prescription_item_id', 'product_id', 'batch_id',
        'quantity', 'user_id', 'created_at',
    ];

    protected $casts = ['quantity' => 'integer', 'created_at' => 'datetime'];

    public function batch()
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }
}
