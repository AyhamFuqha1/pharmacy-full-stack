<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerCreditLimitSemanticsTest extends TestCase
{
    use RefreshDatabase;

    private int $productId;
    private int $batchId;
    private User $admin;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin=User::factory()->create(['role'=>DB::table('roles')->where('name','Admin')->value('id')??1,'force_change_password'=>false]);
        $this->cashier=User::factory()->create(['role'=>DB::table('roles')->where('name','Cashier')->value('id'),'force_change_password'=>false]);
        $product = Product::factory()->create();
        $this->productId = $product->id;
        $this->batchId = DB::table('product_batches')->insertGetId(['product_id'=>$product->id,'batch_number'=>'CREDIT-TEST','expiry_date'=>now()->addYear(),'purchase_price'=>5,'sale_price'=>10,'quantity'=>100,'inventory_state'=>'available','created_at'=>now(),'updated_at'=>now()]);
    }

    public function test_zero_limit_allows_fully_paid_sale_but_blocks_new_receivable(): void
    {
        $customer=$this->customer(0);
        $this->actingAs($this->admin,'sanctum')->postJson('/api/sales-invoices',$this->sale($customer,10))->assertCreated();
        $this->actingAs($this->cashier,'sanctum')->postJson('/api/sales-invoices',$this->sale($customer,9))->assertConflict();
    }

    public function test_projected_receivable_uses_current_balance_plus_new_balance_due(): void
    {
        $allowed=$this->customer(100);
        $this->postReceivable($allowed,70);
        $this->actingAs($this->cashier,'sanctum')->postJson('/api/sales-invoices',$this->sale($allowed,0,30))->assertCreated();

        $blocked=$this->customer(100);
        $this->postReceivable($blocked,70);
        $this->actingAs($this->cashier,'sanctum')->postJson('/api/sales-invoices',$this->sale($blocked,0,40))->assertConflict();
    }

    public function test_null_limit_remains_unconfigured(): void
    {
        $customer=$this->customer(null);
        $this->actingAs($this->cashier,'sanctum')->postJson('/api/sales-invoices',$this->sale($customer,0))->assertCreated();
    }

    private function customer(?float $limit): int { return DB::table('customers')->insertGetId(['name'=>'Credit Customer','phone_num'=>uniqid(),'city'=>'Test','credit_limit'=>$limit,'block_on_limit'=>true]); }
    private function sale(int $customer,float $paid,float $total=10): array { return ['customer_id'=>$customer,'date'=>now()->toDateString(),'discount'=>0,'paid_amount'=>$paid,'payment_method'=>'cash','items'=>[['batch_id'=>$this->batchId,'amount'=>(int)($total/10),'price'=>10,'discount'=>0]]]; }
    private function postReceivable(int $customer,float $amount): void { $this->actingAs($this->admin,'sanctum')->postJson('/api/sales-invoices',$this->sale($customer,0,$amount))->assertCreated(); }
}
