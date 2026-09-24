<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReceiptVoucherRequest extends FormRequest
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
            'amount' => 'required|numeric|gt:0',
            'date' => 'required|date',
            'payment_method' => 'required|in:cash,cheque',
            'cheque_number' => 'required_if:payment_method,cheque|nullable|string|max:50',
            'bank' => 'required_if:payment_method,cheque|nullable|string|max:100',
            'due_date' => 'required_if:payment_method,cheque|nullable|date',
            'allocations' => 'nullable|array',
            'allocations.*.invoice_id' => 'required|integer|exists:sales_invoices,id',
            'allocations.*.amount' => 'required|numeric|gt:0',
        ];
    }
}
