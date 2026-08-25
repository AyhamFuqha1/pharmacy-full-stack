<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'table_name', 'model_name', 'record_id', 'action', 'action_type',
        'old_data', 'new_data', 'old_values', 'new_values', 'created_at',
    ];

    protected $casts = [
        'old_data' => 'array', 'new_data' => 'array',
        'old_values' => 'array', 'new_values' => 'array',
        'created_at' => 'datetime',
    ];
}
