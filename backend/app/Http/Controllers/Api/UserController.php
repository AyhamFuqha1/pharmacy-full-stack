<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\SafeUserResource;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['role_id' => 'nullable|integer', 'requires_password_change' => 'nullable|boolean', 'created_from' => 'nullable|date', 'created_to' => 'nullable|date|after_or_equal:created_from']);
        $search = trim((string) $request->input('search', ''));
        $users = User::query()
            ->with('roleRelation:id,name')
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($request->filled('role_id'), fn ($q) => $q->where('role', $request->role_id))
            ->when($request->filled('requires_password_change'), fn ($q) => $q->where('force_change_password', $request->boolean('requires_password_change')))
            ->when($request->filled('created_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->created_from))->when($request->filled('created_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->created_to))
            ->orderBy('id')
            ->paginate(min(max((int) $request->input('per_page', 15), 1), 100));

        $users->through(fn (User $user) => (new SafeUserResource($user))->resolve());
        return $this->collection($users, 'Users retrieved.');
    }

    public function show(int $id)
    {
        $user = User::query()->with('roleRelation:id,name')->findOrFail($id);
        return $this->success((new SafeUserResource($user))->resolve(), 200, 'User retrieved.');
    }

    public function update(UpdateUserRequest $request, int $id)
    {
        $user = User::query()->findOrFail($id);
        $user->update($request->validated());
        $user->load('roleRelation:id,name');

        return $this->success((new SafeUserResource($user))->resolve(), 200, 'User updated.');
    }
}
