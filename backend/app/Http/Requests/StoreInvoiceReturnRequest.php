<?php

namespace App\Http\Requests;

use DB;
use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceReturnRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $items=collect($this->input('items',[]))->map(function($item){if(isset($item['transaction_quantity'])&&!isset($item['quantity'])){$line=DB::table('prod_sales')->where(['sales_invoice_id'=>$item['invoice_id']??null,'batch_id'=>$item['batch_id']??null])->first();if($line&&$line->transaction_quantity){$factor=(float)$line->base_quantity/(float)$line->transaction_quantity;$item['quantity']=(int)round((float)$item['transaction_quantity']*$factor);}}return$item;})->all();$this->merge(['items'=>$items]);
    }
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
            'items.*.transaction_quantity' => ['nullable','numeric','gt:0'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.customer_credit_unit_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'items.*.return_to_inventory' => ['nullable', 'boolean'],

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
            if (collect($this->items)->pluck('invoice_id')->unique()->count() !== 1) {
                $validator->errors()->add('items', 'All return lines must belong to the same sales invoice.');
                return;
            }

            $batchesInInvoice = DB::table('prod_sales')
                ->where('sales_invoice_id', $invoiceId)
                ->get(['batch_id', 'amount', 'final_total'])
                ->keyBy('batch_id');

            foreach ($this->items as $index => $item) {
              
                 
                if (!$batchesInInvoice->has($item['batch_id'])) {
                    $validator->errors()->add(
                        "items.$index.batch_id",
                        "This batch does not belong to the invoice."
                    );
                    continue;
                }
                $line = $batchesInInvoice[$item['batch_id']];
                if ($item['quantity'] > $line->amount) {
                    $validator->errors()->add(
                        "items.$index.quantity",
                        "Returned quantity exceeds sold quantity."
                    );
                }

                $soldQuantity = max((int) $line->amount, 1);
                $originalMinor = intdiv($this->moneyToMinor($line->final_total) + intdiv($soldQuantity, 2), $soldQuantity);
                $creditMinor = array_key_exists('customer_credit_unit_amount', $item)
                    ? $this->moneyToMinor($item['customer_credit_unit_amount'])
                    : $originalMinor;
                if ($creditMinor > $originalMinor) {
                    $validator->errors()->add("items.$index.customer_credit_unit_amount", __('returns.credit_exceeds_original'));
                }
                $restock = filter_var($item['return_to_inventory'] ?? true, FILTER_VALIDATE_BOOL);
                $reduced = $creditMinor < $originalMinor;
                if (($reduced || ! $restock) && trim((string) ($item['reason'] ?? '')) === '') {
                    $validator->errors()->add("items.$index.reason", $reduced ? __('returns.reduction_reason_required') : __('returns.no_restock_reason_required'));
                }
                if ($reduced && ! $this->user()?->hasPermission('sales.discount')) {
                    $validator->errors()->add("items.$index.customer_credit_unit_amount", __('returns.reduction_not_authorized'));
                }



            }
        });
    }

    private function moneyToMinor(mixed $value): int
    {
        $normalized = trim((string) $value);
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $normalized, $matches)) return PHP_INT_MAX;
        $minor = ((int) $matches[2] * 100) + (int) str_pad($matches[3] ?? '', 2, '0');
        return ($matches[1] ?? '') === '-' ? -$minor : $minor;
    }


}
