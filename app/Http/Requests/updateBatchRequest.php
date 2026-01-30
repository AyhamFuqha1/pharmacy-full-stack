<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class updateBatchRequest extends FormRequest
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
            'product_id' => 'sometimes|exists:products,id',
            'batch_number' => 'sometimes|string|max:100|unique:product_batches,batch_number,' . $this->route('product_batch'),
            'expiry_date' => 'sometimes|date|after_or_equal:today',
            'quantity' => 'sometimes|integer|min:0',
            'purchase_price' => 'sometimes|numeric|min:0',
            'sale_price' => 'sometimes|numeric|gt:purchase_price',
        ];
    }

}
