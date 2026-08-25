<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrescriptionController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            Prescription::query()
                ->when($request->filled('patient_id'), fn ($query) => $query->where('patient_id', (int) $request->patient_id))
                ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', (int) $request->branch_id))
                ->when($request->filled('status'), fn ($query) => $query->where('status', $request->status))
                ->orderByDesc('id')
                ->paginate(min((int) ($request->get('per_page') ?? 15), 100))
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'patient_id' => 'required|exists:patients,id',
            'prescriber_name' => 'required|string|max:255',
            'prescriber_license' => 'required|string|max:100',
            'prescribed_at' => 'required|date',
            'expires_at' => 'nullable|date|after_or_equal:prescribed_at',
            'status' => 'sometimes|in:active,fulfilled,expired,void',
            'notes' => 'nullable|string',
        ]);

        return response()->json(Prescription::query()->create($data), 201);
    }

    public function show(int $id)
    {
        $prescription = Prescription::query()->findOrFail($id);
        $items = PrescriptionItem::query()->where('prescription_id', $id)->get();

        return response()->json([
            'prescription' => $prescription,
            'items' => $items,
        ]);
    }

    public function addItem(Request $request, int $id)
    {
        $prescription = Prescription::query()->findOrFail($id);
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'allowed_quantity' => 'required|integer|min:1',
        ]);

        $product = DB::table('products')->where('id', $data['product_id'])->first(['is_controlled']);
        if (!$product || !(bool) $product->is_controlled) {
            return response()->json(['message' => 'Prescription items must reference controlled medicines.'], 422);
        }

        $item = PrescriptionItem::query()->updateOrCreate(
            ['prescription_id' => $prescription->id, 'product_id' => $data['product_id']],
            ['allowed_quantity' => $data['allowed_quantity']]
        );

        return response()->json($item, 201);
    }

    public function dispense(Request $request, int $id)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        return DB::transaction(function () use ($id, $data) {
            $prescription = Prescription::query()->lockForUpdate()->findOrFail($id);

            if ($prescription->status !== 'active') {
                return response()->json(['message' => 'Prescription is not active.'], 422);
            }

            if ($prescription->expires_at !== null && $prescription->expires_at < now()->toDateString()) {
                $prescription->status = 'expired';
                $prescription->save();
                return response()->json(['message' => 'Prescription is expired.'], 422);
            }

            $product = DB::table('products')->where('id', $data['product_id'])->first(['is_controlled']);
            if (!$product || !(bool) $product->is_controlled) {
                return response()->json(['message' => 'Dispensing workflow is for controlled medicines only.'], 422);
            }

            $item = PrescriptionItem::query()
                ->where('prescription_id', $prescription->id)
                ->where('product_id', $data['product_id'])
                ->lockForUpdate()
                ->first();

            if (!$item) {
                return response()->json(['message' => 'Prescription item not found for this product.'], 422);
            }

            $remaining = (int) $item->allowed_quantity - (int) $item->dispensed_quantity;
            if ($remaining < (int) $data['quantity']) {
                return response()->json(['message' => 'Dispensing quantity exceeds remaining allowed quantity.'], 422);
            }

            $item->dispensed_quantity += (int) $data['quantity'];
            $item->save();

            $allFulfilled = !PrescriptionItem::query()
                ->where('prescription_id', $prescription->id)
                ->whereRaw('dispensed_quantity < allowed_quantity')
                ->exists();

            if ($allFulfilled) {
                $prescription->status = 'fulfilled';
                $prescription->save();
            }

            return response()->json([
                'prescription_id' => $prescription->id,
                'product_id' => (int) $data['product_id'],
                'dispensed_quantity' => (int) $item->dispensed_quantity,
                'remaining_quantity' => ((int) $item->allowed_quantity - (int) $item->dispensed_quantity),
                'status' => $prescription->fresh()->status,
            ]);
        });
    }
}
