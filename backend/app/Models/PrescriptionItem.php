<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrescriptionItem extends Model
{
    protected $fillable = [
        'prescription_id',
        'product_id',
        'allowed_quantity',
        'dispensed_quantity',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
