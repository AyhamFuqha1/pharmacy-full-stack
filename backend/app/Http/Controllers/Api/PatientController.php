<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            Patient::query()
                ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', (int) $request->branch_id))
                ->when($request->filled('search'), fn ($query) => $query->where('full_name', 'like', '%' . $request->search . '%'))
                ->orderBy('full_name')
                ->paginate(min((int) ($request->get('per_page') ?? 15), 100))
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'full_name' => 'required|string|max:255',
            'phone_number' => 'nullable|string|max:50',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female,other',
            'allergies' => 'nullable|string',
            'notes' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        return response()->json(Patient::query()->create($data), 201);
    }

    public function show(int $id)
    {
        return response()->json(Patient::query()->findOrFail($id));
    }

    public function update(Request $request, int $id)
    {
        $patient = Patient::query()->findOrFail($id);
        $data = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'full_name' => 'sometimes|string|max:255',
            'phone_number' => 'nullable|string|max:50',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female,other',
            'allergies' => 'nullable|string',
            'notes' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $patient->fill($data)->save();

        return response()->json($patient->fresh());
    }
}
