<?php

namespace App\Policies;

use App\Models\ProductBatch;
use App\Models\User;

class ProductBatchPolicy
{
    public function viewAny(User $user): bool { return $user->hasPermission('batches.manage'); }
    public function view(User $user, ProductBatch $batch): bool { return $user->hasPermission('batches.manage'); }
    public function create(User $user): bool { return $user->hasPermission('batches.manage'); }
    public function update(User $user, ProductBatch $batch): bool { return $user->hasPermission('batches.manage'); }
    public function delete(User $user, ProductBatch $batch): bool { return $user->hasPermission('batches.manage'); }
}
