<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;

class RoleController extends Controller
{
    public function index()
    {
        return $this->success(Role::query()->orderBy('id')->get(['id', 'name']), 200, 'Roles retrieved.');
    }
}
