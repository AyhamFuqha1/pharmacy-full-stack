<?php

namespace App\Http\Requests;

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
            'date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:date',

            'invoice_discount' => 'nullable|numeric|min:0',

            'items' => 'required|array|min:1',

            'items.*.product_id' => 'required|exists:products,id',
            'items.*.batch_number' => 'required|string|max:100',
            'items.*.expire_date' => 'required|date|after:today',

            'items.*.amount' => 'required|integer|min:1',
            'items.*.purchase_price' => 'required|numeric|min:0',

            'items.*.selling_price' => 'required|numeric|min:0',

            'items.*.item_discount' => 'nullable|numeric|min:0'
        ];

    }
}
