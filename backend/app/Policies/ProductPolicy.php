<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool { return $user->hasPermission('products.manage'); }
    public function view(User $user, Product $product): bool { return $user->hasPermission('products.manage'); }
    public function create(User $user): bool { return $user->hasPermission('products.manage'); }
    public function update(User $user, Product $product): bool { return $user->hasPermission('products.manage'); }
    public function delete(User $user, Product $product): bool { return $user->hasPermission('products.manage'); }
}
