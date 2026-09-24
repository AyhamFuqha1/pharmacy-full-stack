<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class updateaProductRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|min:3|max:255',
            'img' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'nots' => 'nullable|string|max:1000',
            'barcode' => 'sometimes|string|max:50|unique:products,barcode,' . $this->route('product'),
            'is_controlled' => 'sometimes|boolean',
            'control_schedule' => 'nullable|string|max:30|required_if:is_controlled,1',
            'reorder_level' => 'sometimes|numeric|min:0|decimal:0,3',
        ];

    }
}
