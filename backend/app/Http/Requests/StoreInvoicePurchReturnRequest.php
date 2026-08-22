<?php

namespace App\Http\Requests;

use DB;
use Illuminate\Foundation\Http\FormRequest;

class StoreInvoicePurchReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1',

            'items.*.invoice_item_id' => 'required|exists:prod_purch,id',

            'items.*.batch_id' => 'required|exists:product_batches,id',

            'items.*.quantity' => 'required|integer|min:1',

            'items.*.return_price' => 'required|numeric|min:0',

            'items.*.cost_price' => 'required|numeric|min:0',

            'items.*.reasone' => 'nullable|string|max:255',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {

            foreach ($this->items as $index => $item) {

                $invoiceItem = DB::table('prod_purch')
                    ->where('id', $item['invoice_item_id'])
                    ->first();

                if (!$invoiceItem) {
                    $validator->errors()->add(
                        "items.$index.invoice_item_id",
                        "Item not found in original purchase invoice"
                    );
                    continue;
                }

                if ($item['quantity'] > $invoiceItem->amount) {
                    $validator->errors()->add(
                        "items.$index.quantity",
                        "Returned quantity exceeds purchased quantity"
                    );
                }

                $batch = DB::table('product_batches')
                    ->where('id', $item['batch_id'])
                    ->first();

                if (!$batch) {
                    $validator->errors()->add(
                        "items.$index.batch_id",
                        "Batch not found"
                    );
                    continue;
                }

                if ($item['quantity'] > $batch->quantity) {
                    $validator->errors()->add(
                        "items.$index.quantity",
                        "Not enough stock in this batch"
                    );
                }

                
            }
        });
    }
}
  