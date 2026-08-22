<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesInvoiceRequest extends FormRequest
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
            'customer_id' => 'required|exists:customers,id',
            'date' => 'required|date',
            'discount' => 'sometimes|numeric|min:0',
            'paid_amount'=>'required|numeric|min:0',
            'payment_method' => 'required|in:cash,cheque,visa',
            'items' => 'required|array|min:1',
            'items.*.batch_id' => 'required|exists:product_batches,id',
            'items.*.amount' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.discount' => 'sometimes|numeric|min:0',
        ];
    }
}
