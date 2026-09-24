<?php

namespace App\Http\Requests;

use DB;
use Illuminate\Foundation\Http\FormRequest;

class StoreInvoicePurchReturnRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $items=collect($this->input('items',[]))->map(function($item){if(isset($item['transaction_quantity'])&&!isset($item['quantity'])){$line=DB::table('prod_purch')->where('id',$item['invoice_item_id']??null)->first();if($line&&$line->conversion_factor)$item['quantity']=(int)round((float)$item['transaction_quantity']*(float)$line->conversion_factor);}return$item;})->all();$this->merge(['items'=>$items]);
    }
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
            'items.*.transaction_quantity' => 'nullable|numeric|gt:0',
            'items.*.supplier_credit_unit_amount' => 'nullable|numeric|decimal:0,2|min:0',

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

                $historicalMinor = $this->moneyToMinor($invoiceItem->purchase_price ?? 0);
                $creditMinor = array_key_exists('supplier_credit_unit_amount', $item)
                    ? $this->moneyToMinor($item['supplier_credit_unit_amount'])
                    : $historicalMinor;
                if ($creditMinor > $historicalMinor) {
                    $validator->errors()->add("items.$index.supplier_credit_unit_amount", __('returns.supplier_credit_exceeds_cost'));
                }
                if ($creditMinor < $historicalMinor && trim((string) ($item['reasone'] ?? '')) === '') {
                    $validator->errors()->add("items.$index.reasone", __('returns.supplier_credit_reason_required'));
                }
                if ($creditMinor < $historicalMinor && ! $this->user()?->hasPermission('purchase_returns.override_credit')) {
                    $validator->errors()->add("items.$index.supplier_credit_unit_amount", __('returns.reduction_not_authorized'));
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
