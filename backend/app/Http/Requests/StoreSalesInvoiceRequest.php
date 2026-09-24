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
            'branch_id' => 'nullable|exists:branches,id',
            'patient_id' => 'nullable|exists:patients,id',
            'prescription_id' => 'nullable|exists:prescriptions,id',
            'date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:date',
            'discount' => 'sometimes|numeric|min:0',
            'invoice_discount_type' => 'nullable|in:fixed,percentage',
            'invoice_discount_value' => 'nullable|numeric|min:0',
            'invoice_discount_reason' => 'nullable|string|max:255',
            'paid_amount'=>'required|numeric|min:0',
            'payment_method' => 'required|in:cash,cheque,visa',
            'cheque_number' => 'required_if:payment_method,cheque|nullable|string|max:100',
            'cheque_bank' => 'required_if:payment_method,cheque|nullable|string|max:150',
            'cheque_due_date' => 'required_if:payment_method,cheque|nullable|date',
            'items' => 'required|array|min:1',
            'items.*.batch_id' => 'nullable|exists:product_batches,id|required_without_all:items.*.product_id,items.*.barcode',
            'items.*.product_id' => 'nullable|exists:products,id|required_without_all:items.*.batch_id,items.*.barcode',
            'items.*.barcode' => 'nullable|string|max:50|exists:products,barcode|required_without_all:items.*.batch_id,items.*.product_id',
            'items.*.prescription_item_id' => 'nullable|exists:prescription_items,id',
            'items.*.amount' => 'required|integer|min:1',
            'items.*.unit_id' => 'nullable|exists:units,id',
            'items.*.price' => 'nullable|numeric|min:0',
            'items.*.discount' => 'sometimes|numeric|min:0',
            'items.*.discount_type' => 'nullable|in:fixed,percentage',
            'items.*.discount_value' => 'nullable|numeric|min:0',
            'items.*.discount_reason' => 'nullable|string|max:255',
            'items.*.override_reason' => 'nullable|string|max:255',
            'credit_override_reason' => 'nullable|string|max:255',
        ];
    }
}
