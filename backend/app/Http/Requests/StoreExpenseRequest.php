<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|gt:0',
            'payment_type' => 'required|in:cash,bank',
            'expense_date' => 'required|date',
            'branch_id' => 'nullable|exists:branches,id',
            'expense_account_id' => 'nullable|exists:accounts,id',
            'description' => 'required|string',
            'external_reference' => 'nullable|string',
            'notes' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        if (app()->getLocale() === 'ar') {
            return [
                'payment_type.required' => 'طريقة الدفع مطلوبة.',
                'expense_date.required' => 'تاريخ المصروف مطلوب.',
            ];
        }

        return [
            'payment_type.required' => 'The payment type field is required.',
            'expense_date.required' => 'The expense date field is required.',
        ];
    }
}
