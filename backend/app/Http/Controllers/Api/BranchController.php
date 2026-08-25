<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            Branch::query()
                ->when($request->filled('active'), fn ($query) => $query->where('is_active', (bool) $request->boolean('active')))
                ->orderBy('name')
                ->paginate(min((int) ($request->get('per_page') ?? 15), 100))
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:branches,code',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'is_active' => 'sometimes|boolean',
        ]);

        return response()->json(Branch::query()->create($data), 201);
    }

    public function show(int $id)
    {
        return response()->json(Branch::query()->findOrFail($id));
    }

    public function update(Request $request, int $id)
    {
        $branch = Branch::query()->findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|max:50|unique:branches,code,' . $id,
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'is_active' => 'sometimes|boolean',
        ]);

        $branch->fill($data)->save();

        return response()->json($branch->fresh());
    }
}
