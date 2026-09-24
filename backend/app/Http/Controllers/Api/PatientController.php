<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['gender' => 'nullable|in:male,female,other', 'branch_id' => 'nullable|integer', 'is_active' => 'nullable|boolean', 'date_of_birth_from' => 'nullable|date', 'date_of_birth_to' => 'nullable|date|after_or_equal:date_of_birth_from']);
        return response()->json(
            Patient::query()
                ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', (int) $request->branch_id))
                ->when($request->filled('search'), fn ($query) => $query->where(fn ($q) => $q->where('full_name', 'like', '%' . $request->search . '%')->orWhere('phone_number', 'like', '%' . $request->search . '%')))
                ->when($request->filled('name'), fn ($q) => $q->where('full_name', 'like', '%'.$request->name.'%'))->when($request->filled('phone'), fn ($q) => $q->where('phone_number', 'like', '%'.$request->phone.'%'))
                ->when($request->filled('gender'), fn ($q) => $q->where('gender', $request->gender))->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
                ->when($request->filled('date_of_birth_from'), fn ($q) => $q->whereDate('date_of_birth', '>=', $request->date_of_birth_from))->when($request->filled('date_of_birth_to'), fn ($q) => $q->whereDate('date_of_birth', '<=', $request->date_of_birth_to))
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
