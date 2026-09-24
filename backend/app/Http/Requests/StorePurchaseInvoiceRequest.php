<?php

namespace App\Http\Requests;

use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseInvoiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'provider_id' => 'required|exists:providers,id',
            'branch_id' => 'nullable|exists:branches,id',
            'date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:date',

            'invoice_discount' => 'nullable|numeric|min:0',

            'items' => 'required|array|min:1',

            'items.*.product_id' => 'required|exists:products,id',
            'items.*.batch_number' => 'required|string|max:100',
            'items.*.expire_date' => 'nullable|date|after:today',

            'items.*.amount' => 'required|integer|min:1',
            'items.*.unit_id' => 'nullable|exists:units,id',
            'items.*.purchase_price' => 'required|numeric|min:0',

            'items.*.selling_price' => 'nullable|numeric|min:0',
            'items.*.conversion_factor' => 'prohibited',
            'items.*.base_quantity' => 'prohibited',

            'items.*.item_discount' => 'nullable|numeric|min:0'
        ];

    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ($this->input('items', []) as $index => $item) {
                if (empty($item['unit_id']) || empty($item['product_id'])) continue;
                $valid = DB::table('product_units')->where([
                    'product_id' => $item['product_id'],
                    'unit_id' => $item['unit_id'],
                    'is_active' => true,
                ])->where(fn ($query) => $query->where('is_purchase_unit', true)->orWhere('is_base', true))
                    ->where('base_quantity', '>', 0)->exists();
                if (!$valid) $validator->errors()->add("items.$index.unit_id", 'Selected unit is not an active purchase unit for this product.');
            }
        });
    }
}
