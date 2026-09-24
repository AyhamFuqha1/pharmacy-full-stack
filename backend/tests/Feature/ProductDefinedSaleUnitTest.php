<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductDefinedSaleUnitTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;

    protected function setUp():void
    {
        parent::setUp();$this->seed();$this->admin=User::factory()->create(['role'=>1,'force_change_password'=>false]);$this->actingAs($this->admin,'sanctum');
    }

    public function test_user_defines_one_base_and_product_specific_packages_without_master_unit_setup():void
    {
        $product=Product::factory()->create(['barcode'=>'DIRECT-UNITS']);
        $base=$this->postJson("/api/products/$product->id/units",['name'=>'حبة','is_base'=>true,'base_quantity'=>99,'sale_price'=>5,'is_sale_unit'=>true])->assertCreated()->assertJsonPath('data.name','حبة')->assertJsonPath('data.base_quantity',1)->assertJsonPath('data.is_base',1)->json('data');
        $box=$this->postJson("/api/products/$product->id/units",['name'=>'علبة','base_quantity'=>20,'sale_price'=>20,'is_sale_unit'=>true])->assertCreated()->assertJsonPath('data.name','علبة')->json('data');
        $carton=$this->postJson("/api/products/$product->id/units",['name'=>'كرتونة','base_quantity'=>100,'sale_price'=>90,'is_sale_unit'=>true])->assertCreated()->json('data');
        $this->assertEquals(20,$box['base_quantity']);$this->assertEquals(90,$carton['sale_price']);
        $this->postJson("/api/products/$product->id/units",['name'=>'حبة','is_base'=>false,'base_quantity'=>2,'sale_price'=>8])->assertUnprocessable();
        $this->postJson("/api/products/$product->id/units",['name'=>'قطعة','is_base'=>true,'sale_price'=>4])->assertUnprocessable()->assertJsonValidationErrors('is_base');
        $this->postJson("/api/products/$product->id/units",['name'=>'شريط','base_quantity'=>1,'sale_price'=>10])->assertUnprocessable()->assertJsonValidationErrors('base_quantity');
        $this->deleteJson("/api/products/$product->id/units/{$base['id']}")->assertConflict();
        $this->getJson("/api/products/$product->id/units")->assertOk()->assertJsonCount(3,'data');
    }

    public function test_sale_defaults_to_base_price_and_package_sale_preserves_fefo_snapshots_after_price_edit():void
    {
        $product=Product::factory()->create(['barcode'=>'DIRECT-SALE']);$customer=DB::table('customers')->insertGetId(['name'=>'Unit Customer','phone_num'=>'UNIT-C','city'=>'Test']);
        $base=$this->postJson("/api/products/$product->id/units",['name'=>'حبة','is_base'=>true,'sale_price'=>5])->assertCreated()->json('data');
        $box=$this->postJson("/api/products/$product->id/units",['name'=>'علبة','base_quantity'=>20,'sale_price'=>20])->assertCreated()->json('data');
        $carton=$this->postJson("/api/products/$product->id/units",['name'=>'كرتونة','base_quantity'=>100,'sale_price'=>90])->assertCreated()->json('data');
        $first=$this->batch($product->id,'EARLY',30,now()->addMonths(2)->toDateString());$second=$this->batch($product->id,'LATER',150,now()->addMonths(5)->toDateString());
        $lookup=$this->getJson('/api/product/barcode/DIRECT-SALE')->assertOk()->assertJsonPath('data.base_unit.name','حبة')->assertJsonPath('data.default_price',5)->assertJsonPath('data.pricing_source','product_sale_unit');
        $this->assertSame([5,20,90],array_map(fn($row)=>(int)$row['sale_price'],$lookup->json('data.sale_units')));
        $baseSale=$this->postJson('/api/sales-invoices',['customer_id'=>$customer,'date'=>now()->toDateString(),'paid_amount'=>5,'payment_method'=>'cash','items'=>[['product_id'=>$product->id,'amount'=>1]]])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('prod_sales',['sales_invoice_id'=>$baseSale,'transaction_unit_id'=>$base['unit_id'],'base_quantity'=>1,'original_unit_price'=>5,'applied_unit_price'=>5]);
        $packageSale=$this->postJson('/api/sales-invoices',['customer_id'=>$customer,'date'=>now()->toDateString(),'paid_amount'=>40,'payment_method'=>'cash','items'=>[['product_id'=>$product->id,'unit_id'=>$box['unit_id'],'amount'=>2]]])->assertCreated()->json('data.id');
        $this->assertEquals(29,DB::table('prod_sales')->where(['sales_invoice_id'=>$packageSale,'batch_id'=>$first])->value('base_quantity'));
        $this->assertEquals(11,DB::table('prod_sales')->where(['sales_invoice_id'=>$packageSale,'batch_id'=>$second])->value('base_quantity'));
        $this->assertEquals(40,DB::table('prod_sales')->where('sales_invoice_id',$packageSale)->sum('base_quantity'));
        $this->putJson("/api/products/$product->id/units/{$box['id']}",['sale_price'=>25])->assertOk();
        $this->assertEquals(20,DB::table('prod_sales')->where('sales_invoice_id',$packageSale)->value('original_unit_price'));
        $this->putJson("/api/products/$product->id/units/{$box['id']}",['base_quantity'=>25])->assertConflict();
        $this->deleteJson("/api/products/$product->id/units/{$box['id']}")->assertOk()->assertJsonPath('data.is_active',0);
        $this->assertDatabaseHas('product_units',['id'=>$carton['id'],'is_active'=>1]);
    }

    public function test_price_override_needs_permission_but_does_not_need_a_reason_and_below_cost_stays_separate():void
    {
        $product=Product::factory()->create(['barcode'=>'PRICE-OVERRIDE']);
        $customer=DB::table('customers')->insertGetId(['name'=>'Override Customer','phone_num'=>'OVERRIDE-C','city'=>'Test']);
        $base=$this->postJson("/api/products/$product->id/units",['name'=>'Tablet','is_base'=>true,'sale_price'=>5])->assertCreated()->json('data');
        DB::table('product_batches')->insert(['product_id'=>$product->id,'batch_number'=>'OVERRIDE-BATCH','expiry_date'=>now()->addYear()->toDateString(),'purchase_price'=>4,'sale_price'=>999,'quantity'=>20,'inventory_state'=>'available','created_at'=>now(),'updated_at'=>now()]);
        $payload=['customer_id'=>$customer,'date'=>now()->toDateString(),'paid_amount'=>6,'payment_method'=>'cash','items'=>[['product_id'=>$product->id,'unit_id'=>$base['unit_id'],'amount'=>1,'price'=>6]]];

        $this->actingAs($this->userWithPermissions(['sales.create']),'sanctum');
        $this->postJson('/api/sales-invoices',$payload)->assertForbidden();

        $this->actingAs($this->userWithPermissions(['sales.create','sales.override_price']),'sanctum');
        $sale=$this->postJson('/api/sales-invoices',$payload)->assertCreated()->json('data.id');
        $this->assertDatabaseHas('prod_sales',['sales_invoice_id'=>$sale,'original_unit_price'=>5,'applied_unit_price'=>6,'price_overridden'=>1,'override_reason'=>null]);

        $payload['paid_amount']=3;
        $payload['items'][0]['price']=3;
        $this->postJson('/api/sales-invoices',$payload)->assertForbidden();
    }

    private function batch(int$product,string$number,int$quantity,string$expiry):int{return DB::table('product_batches')->insertGetId(['product_id'=>$product,'batch_number'=>$number,'expiry_date'=>$expiry,'purchase_price'=>1,'sale_price'=>999,'quantity'=>$quantity,'inventory_state'=>'available','created_at'=>now(),'updated_at'=>now()]);}
    private function userWithPermissions(array$names):User{$role=DB::table('roles')->insertGetId(['name'=>'Sale Override '.uniqid(),'created_at'=>now(),'updated_at'=>now()]);foreach(DB::table('permissions')->whereIn('name',$names)->pluck('id')as$permission)DB::table('role_permissions')->insert(['role_id'=>$role,'permission_id'=>$permission]);return User::factory()->create(['role'=>$role,'force_change_password'=>false]);}
}
