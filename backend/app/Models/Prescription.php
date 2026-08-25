<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Prescription extends Model
{
    protected $fillable = [
        'branch_id',
        'patient_id',
        'prescriber_name',
        'prescriber_license',
        'prescribed_at',
        'expires_at',
        'status',
        'notes',
    ];
}
