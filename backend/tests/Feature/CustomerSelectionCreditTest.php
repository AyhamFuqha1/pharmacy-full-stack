<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerSelectionCreditTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_credit_summary_and_360_safely_use_actual_sales_return_schema():void
    {
        $this->seed();$admin=User::factory()->create(['role'=>1,'force_change_password'=>false]);$this->actingAs($admin,'sanctum');
        $customer=DB::table('customers')->insertGetId(['name'=>'Ahmad Saleh','phone_num'=>'0599000002','city'=>'Test','credit_limit'=>100,'credit_warning_threshold'=>80,'block_on_limit'=>1]);
        $product=Product::factory()->create();$batch=DB::table('product_batches')->insertGetId(['product_id'=>$product->id,'batch_number'=>'CUSTOMER-360','expiry_date'=>now()->addYear(),'purchase_price'=>4,'sale_price'=>10,'quantity'=>10,'inventory_state'=>'available','created_at'=>now(),'updated_at'=>now()]);
        $sale=DB::table('sales_invoices')->insertGetId(['document_number'=>'SAL-CUSTOMER-360','customer_id'=>$customer,'user_id'=>$admin->id,'total_price'=>100,'discount'=>0,'paid_amount'=>20,'payment_method'=>'cash','profit'=>50,'date'=>now()->toDateString(),'due_date'=>now()->toDateString(),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('invoice_returns')->insert(['invoice_id'=>$sale,'product_id'=>$product->id,'batch_id'=>$batch,'quantity'=>2,'price'=>10,'reason'=>'Return','created_at'=>now(),'updated_at'=>now()]);
        $this->getJson("/api/customers/$customer")->assertOk()->assertJsonPath('data.name','Ahmad Saleh')->assertJsonPath('data.credit_limit',100)->assertJsonStructure(['data'=>['current_receivable','available_credit','warning','exceeded']]);
        $this->getJson("/api/customers/$customer/360")->assertOk()->assertJsonPath('data.profile.name','Ahmad Saleh')->assertJsonPath('data.ar_aging.data.0.invoices.0.settled_amount',40)->assertJsonStructure(['data'=>['current_balance','credit_state'=>['limit','warning_threshold','available_credit'],'outstanding_invoices']]);
    }
}
