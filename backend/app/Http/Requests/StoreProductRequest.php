<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
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
            'name' => 'required|string|min:3|max:255',
            'img' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'nots' => 'nullable|string|max:1000',
            'barcode' => 'required|string|max:50|unique:products,barcode,' . $this->route('product'),
            'is_controlled' => 'sometimes|boolean',
            'control_schedule' => 'nullable|string|max:30|required_if:is_controlled,1',
        ];

    }
}
