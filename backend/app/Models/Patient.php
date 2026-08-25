<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Patient extends Model
{
    protected $fillable = [
        'branch_id',
        'full_name',
        'phone_number',
        'date_of_birth',
        'gender',
        'allergies',
        'notes',
        'is_active',
    ];
}
