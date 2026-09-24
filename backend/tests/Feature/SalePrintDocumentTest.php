<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalePrintDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_print_sale_with_nullable_customer_and_branch(): void
    {
        $this->seed();
        $user=User::factory()->create(['role'=>1,'force_change_password'=>false,'branch_id'=>null]);
        $customer=DB::table('customers')->insertGetId(['name'=>'Print Customer','phone_num'=>'PRINT-1','city'=>'Test']);
        $product=Product::factory()->create();
        $batch=DB::table('product_batches')->insertGetId(['product_id'=>$product->id,'branch_id'=>null,'batch_number'=>'PRINT-LOT','expiry_date'=>now()->addYear(),'purchase_price'=>4,'sale_price'=>10,'quantity'=>5,'inventory_state'=>'available','created_at'=>now(),'updated_at'=>now()]);
        $sale=DB::table('sales_invoices')->insertGetId(['document_number'=>'SAL-PRINT-1','customer_id'=>$customer,'user_id'=>$user->id,'branch_id'=>null,'total_price'=>17,'discount'=>1,'paid_amount'=>10,'payment_method'=>'cash','profit'=>8,'date'=>now()->toDateString(),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('prod_sales')->insert(['sales_invoice_id'=>$sale,'batch_id'=>$batch,'amount'=>2,'price'=>10,'discount'=>2,'cost_price'=>4,'line_total'=>18,'invoice_discount_share'=>1,'final_total'=>17,'profit'=>9,'created_at'=>now(),'updated_at'=>now()]);

        $this->actingAs($user,'sanctum')->getJson("/api/print-documents/sales_invoice/$sale")
            ->assertOk()
            ->assertJsonPath('data.meta.title','Sales Invoice')
            ->assertJsonPath('data.meta.document_number','SAL-PRINT-1')
            ->assertJsonPath('data.meta.customer.name','Print Customer')
            ->assertJsonPath('data.meta.branch',null)
            ->assertJsonPath('data.meta.generated_by.name',$user->name)
            ->assertJsonPath('data.content.items.0.product',$product->name)
            ->assertJsonPath('data.content.items.0.batch','PRINT-LOT')
            ->assertJsonPath('data.content.totals.subtotal',20)
            ->assertJsonPath('data.content.totals.line_discounts',2)
            ->assertJsonPath('data.content.totals.invoice_discount',1)
            ->assertJsonPath('data.content.totals.total',17)
            ->assertJsonPath('data.content.totals.paid',10)
            ->assertJsonPath('data.content.totals.balance_due',7);
    }
}
