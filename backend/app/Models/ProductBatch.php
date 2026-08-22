<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductBatch extends Model
{

    protected $fillable = [
        'product_id',
        'batch_number',
        'expiry_date',
        'quantity',
        'purchase_price',
        'sale_price'
    ];
}
