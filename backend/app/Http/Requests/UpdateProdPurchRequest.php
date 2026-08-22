<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProdPurchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'provider_id' => 'sometimes|exists:providers,id',
            'date' => 'sometimes|date',
            'due_date' => 'nullable|date|after_or_equal:date',

            'invoice_discount' => 'nullable|numeric|min:0',

            'items' => 'sometimes|array|min:1',

            'items.*.id' => 'nullable|exists:prod_purch,id',
            'items.*.product_id' => 'sometimes|exists:products,id',
            'items.*.batch_number' => 'sometimes|string|max:100',
            'items.*.expire_date' => 'sometimes|date|after:today',
            'items.*.amount' => 'sometimes|integer|min:1',
            'items.*.purchase_price' => 'sometimes|numeric|min:0',
            'items.*.selling_price' => 'sometimes|numeric|min:0',
            'items.*.item_discount' => 'nullable|numeric|min:0'
        ];


    }
}
