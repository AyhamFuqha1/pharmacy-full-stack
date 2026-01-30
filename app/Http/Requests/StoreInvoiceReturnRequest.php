<?php

namespace App\Http\Requests;

use DB;
use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceReturnRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1'],

            'items.*.invoice_id' => ['required', 'integer', 'exists:sales_invoices,id'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.batch_id' => ['required', 'integer', 'exists:product_batches,id'],

            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['required', 'numeric', 'min:0'],

            'items.*.reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator)
    {
        if ($validator->errors()->count() > 0) {
            return;
        }
      
        if (empty($this->items)) {
            return;
        }
        $validator->after(function ($validator) {

          
            $invoiceId = $this->items[0]['invoice_id'];

            $batchesInInvoice = DB::table('prod_sales')
                ->where('sales_invoice_id', $invoiceId)
                ->get(['batch_id', 'amount'])
                 ->pluck('amount', 'batch_id');

            foreach ($this->items as $index => $item) {
              
                 
                if (!$batchesInInvoice->has($item['batch_id'])) {
                    $validator->errors()->add(
                        "items.$index.batch_id",
                        "This batch does not belong to the invoice."
                    );
                    continue;
                }
                if ($item['quantity'] > $batchesInInvoice[$item['batch_id']]) {
                    $validator->errors()->add(
                        "items.$index.quantity",
                        "Returned quantity exceeds sold quantity."
                    );
                }



            }
        });
    }


}
