<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
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
   public function rules()
{
    return [
        'name'      => 'sometimes|string|max:255',
        'phone_num' => 'sometimes|string|max:20',
        'city'      => 'nullable|string|max:255',
        'credit_limit' => 'nullable|numeric|min:0',
        'credit_warning_threshold' => 'nullable|numeric|min:0',
        'block_on_limit' => 'sometimes|boolean',
    ];
}

}
