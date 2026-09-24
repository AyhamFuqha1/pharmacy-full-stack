<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Http\Requests\DispensePrescriptionRequest;
use App\Services\PrescriptionDispensingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrescriptionController extends Controller
{
    public function __construct(private PrescriptionDispensingService $dispensing) {}

    public function index(Request $request)
    {
        $request->validate(['status' => 'nullable|in:active,fulfilled,expired,void', 'prescribed_from' => 'nullable|date', 'prescribed_to' => 'nullable|date|after_or_equal:prescribed_from', 'expires_from' => 'nullable|date', 'expires_to' => 'nullable|date|after_or_equal:expires_from', 'product_id' => 'nullable|integer']);
        $prescriptions = Prescription::query()
                ->with(['patient:id,full_name', 'items.product:id,name,barcode'])
                ->when($request->filled('patient_id'), fn ($query) => $query->where('patient_id', (int) $request->patient_id))
                ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', (int) $request->branch_id))
                ->when($request->filled('status'), fn ($query) => $query->where('status', $request->status))
                ->when($request->filled('patient_search'), fn ($q) => $q->whereHas('patient', fn ($p) => $p->where('full_name', 'like', '%'.$request->patient_search.'%')))
                ->when($request->filled('prescriber_search'), fn ($q) => $q->where(fn ($p) => $p->where('prescriber_name', 'like', '%'.$request->prescriber_search.'%')->orWhere('prescriber_license', 'like', '%'.$request->prescriber_search.'%')))
                ->when($request->filled('prescribed_from'), fn ($q) => $q->whereDate('prescribed_at', '>=', $request->prescribed_from))->when($request->filled('prescribed_to'), fn ($q) => $q->whereDate('prescribed_at', '<=', $request->prescribed_to))
                ->when($request->filled('expires_from'), fn ($q) => $q->whereDate('expires_at', '>=', $request->expires_from))->when($request->filled('expires_to'), fn ($q) => $q->whereDate('expires_at', '<=', $request->expires_to))
                ->when($request->filled('product_id'), fn ($q) => $q->whereHas('items', fn ($i) => $i->where('product_id', $request->product_id)))
                ->when($request->filled('product_search'), fn ($q) => $q->whereHas('items.product', fn ($p) => $p->where(fn ($s) => $s->where('name', 'like', '%'.$request->product_search.'%')->orWhere('barcode', 'like', '%'.$request->product_search.'%'))))
                ->orderByDesc('id')
                ->paginate(min(max((int) ($request->get('per_page') ?? 15), 1), 100));
        $prescriptions->through(fn (Prescription $prescription) => $this->present($prescription));

        return $this->collection($prescriptions, 'Prescriptions retrieved.');
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
        $prescription = Prescription::query()
            ->with(['patient:id,full_name', 'items.product:id,name,barcode'])
            ->findOrFail($id);

        return $this->success($this->present($prescription), 200, 'Prescription retrieved.');
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

    public function dispense(DispensePrescriptionRequest $request, int $id)
    {
        $data = $request->validated();
        $result = $this->dispensing->dispense($id, (int) $data['product_id'], (int) $data['quantity'], $request->user());

        return $this->success($result, 200, 'Prescription dispensed.');
    }

    private function present(Prescription $prescription): array
    {
        return [
            'id' => $prescription->id,
            'branch_id' => $prescription->branch_id,
            'patient' => $prescription->patient ? ['id' => $prescription->patient->id, 'full_name' => $prescription->patient->full_name] : null,
            'prescriber_name' => $prescription->prescriber_name,
            'prescriber_license' => $prescription->prescriber_license,
            'prescribed_at' => $prescription->prescribed_at?->format('Y-m-d'),
            'expires_at' => $prescription->expires_at?->format('Y-m-d'),
            'status' => $prescription->status,
            'notes' => $prescription->notes,
            'items' => $prescription->items->map(fn (PrescriptionItem $item) => [
                'id' => $item->id,
                'product' => $item->product ? ['id' => $item->product->id, 'name' => $item->product->name, 'barcode' => $item->product->barcode] : null,
                'allowed_quantity' => (int) $item->allowed_quantity,
                'dispensed_quantity' => (int) $item->dispensed_quantity,
                'remaining_quantity' => (int) $item->allowed_quantity - (int) $item->dispensed_quantity,
            ])->values()->all(),
            'created_at' => $prescription->created_at,
            'updated_at' => $prescription->updated_at,
        ];
    }
}
