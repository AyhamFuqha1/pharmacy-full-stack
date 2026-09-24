<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesInvoiceDiscountTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin=User::factory()->create(['role'=>1,'force_change_password'=>false]);
        $this->customer=DB::table('customers')->insertGetId(['name'=>'Invoice Discount Customer','phone_num'=>'INV-DISC','city'=>'Test','credit_limit'=>1,'block_on_limit'=>true]);
        $this->actingAs($this->admin,'sanctum');
    }

    public function test_invoice_discount_none_fixed_percentage_combined_and_payment_interaction(): void
    {
        $none=$this->sale(10,10)->assertCreated()->json('data.id');
        $this->assertInvoice($none,10,0,'fixed',0);

        $fixed=$this->sale(10,8,['invoice_discount_type'=>'fixed','invoice_discount_value'=>2,'invoice_discount_reason'=>'Fixed promotion'])->assertCreated()->json('data.id');
        $this->assertInvoice($fixed,8,2,'fixed',2);

        $percentage=$this->sale(10,9,['invoice_discount_type'=>'percentage','invoice_discount_value'=>10,'invoice_discount_reason'=>'Percentage promotion'])->assertCreated()->json('data.id');
        $this->assertInvoice($percentage,9,1,'percentage',10);

        $combined=$this->sale(20,16.2,['invoice_discount_type'=>'percentage','invoice_discount_value'=>10,'invoice_discount_reason'=>'Invoice loyalty','line_discount'=>2])->assertCreated()->json('data.id');
        $this->assertInvoice($combined,16.2,1.8,'percentage',10);
        $this->assertDatabaseHas('prod_sales',['sales_invoice_id'=>$combined,'discount'=>2,'invoice_discount_share'=>1.8,'final_total'=>16.2]);

        $this->assertSame(0.0,(float)DB::table('sales_invoices')->where('id',$fixed)->value('total_price')-(float)DB::table('sales_invoices')->where('id',$fixed)->value('paid_amount'));
    }

    private function sale(float $price,float $paid,array $extra=[])
    {
        $product=Product::factory()->create();
        $batch=DB::table('product_batches')->insertGetId(['product_id'=>$product->id,'batch_number'=>'INV-DISC-'.uniqid(),'expiry_date'=>now()->addYear(),'purchase_price'=>1,'sale_price'=>$price,'quantity'=>5,'inventory_state'=>'available','created_at'=>now(),'updated_at'=>now()]);
        $line=['batch_id'=>$batch,'amount'=>1,'price'=>$price,'discount'=>0];
        if(isset($extra['line_discount']))$line=array_merge($line,['discount_type'=>'fixed','discount_value'=>$extra['line_discount'],'discount_reason'=>'Line promotion']);
        unset($extra['line_discount']);
        return $this->postJson('/api/sales-invoices',array_merge(['customer_id'=>$this->customer,'date'=>now()->toDateString(),'discount'=>0,'paid_amount'=>$paid,'payment_method'=>'cash','items'=>[$line]],$extra));
    }

    private function assertInvoice(int $id,float $total,float $discount,string $type,float $value): void
    {
        $this->assertDatabaseHas('sales_invoices',['id'=>$id,'total_price'=>$total,'discount'=>$discount,'discount_type'=>$type,'discount_value'=>$value]);
    }
}
