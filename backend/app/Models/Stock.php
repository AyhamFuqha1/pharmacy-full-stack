<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    public $timestamps = false;

    protected $table = 'stock';

    protected $fillable = [
        'batch_id',
        'quantity',
        'type',
        'reference_type',
        'reference_id',
        'user_id',
        'created_at',
    ];

    public function batch()
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }
}
