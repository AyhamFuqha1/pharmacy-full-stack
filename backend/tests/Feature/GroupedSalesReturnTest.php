<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use App\Services\AccountingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GroupedSalesReturnTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp(); $this->seed();
        $this->admin=User::factory()->create(['role'=>1,'force_change_password'=>false]);
        $this->actingAs($this->admin,'sanctum');
        DB::table('accounting_periods')->insert(['name'=>'Grouped Return','period_year'=>now()->year,'period_month'=>now()->month,'start_date'=>now()->startOfMonth()->toDateString(),'end_date'=>now()->endOfMonth()->toDateString(),'status'=>'open','created_at'=>now(),'updated_at'=>now()]);
    }

    public function test_three_lines_create_one_atomic_document_voucher_list_detail_link_and_print(): void
    {
        [$invoice,$customer,$fixtures]=$this->sale();
        $before=collect($fixtures)->mapWithKeys(fn($x)=>[$x['batch']->id=>(int)$x['batch']->fresh()->quantity]);
        $payload=['items'=>collect($fixtures)->values()->map(fn($x,$i)=>[
            'invoice_id'=>$invoice,'product_id'=>$x['product']->id,'batch_id'=>$x['batch']->id,'quantity'=>1,
            'customer_credit_unit_amount'=>[5,90,6][$i],'return_to_inventory'=>[true,false,true][$i],
            'reason'=>$i===1?'Damaged; reduced credit and no restock':null,
        ])->all()];
        $response=$this->postJson('/api/sales-returns',$payload)->assertCreated();
        $documentId=(int)$response->json('data.id');

        $this->assertDatabaseCount('sales_returns',1);
        $this->assertSame(3,DB::table('invoice_returns')->where('sales_return_id',$documentId)->count());
        $this->assertSame(0,DB::table('invoice_returns')->where('sales_return_id',$documentId)->whereNotNull('document_number')->count());
        $this->assertSame(1,DB::table('document_sequences')->where(['document_type'=>'sales_return','year'=>(int)now()->format('Y')])->value('last_number'));
        $this->assertSame(1,DB::table('vouchers')->where(['source_type'=>'sales_return','source_id'=>$documentId])->count());
        $this->assertSame(2,DB::table('stock')->where(['reference_type'=>'sales_return','reference_id'=>$documentId])->count());
        foreach($fixtures as $i=>$x)$this->assertSame($before[$x['batch']->id]+($i===1?0:1),(int)$x['batch']->fresh()->quantity);
        $this->assertEquals(10,app(AccountingReportService::class)->customerStatement($customer)['balance']);
        $entry=DB::table('journal_entries as e')->join('vouchers as v','v.id','=','e.voucher_id')->where(['v.source_type'=>'sales_return','v.source_id'=>$documentId])->first(['e.id']);
        $debits=(float)DB::table('journal_entry_lines')->where('journal_entry_id',$entry->id)->where('side','debit')->sum('amount');
        $credits=(float)DB::table('journal_entry_lines')->where('journal_entry_id',$entry->id)->where('side','credit')->sum('amount');
        $this->assertSame($debits,$credits);

        $this->getJson('/api/sales-returns')->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.0.item_count',3)->assertJsonPath('data.0.customer_credit_value',101);
        $this->getJson("/api/sales-returns/$documentId")->assertOk()->assertJsonCount(3,'data.lines')->assertJsonPath('data.totals.customer_credit_total',101);
        $this->getJson("/api/sales-invoices/$invoice")->assertOk()->assertJsonCount(1,'data.linked_returns')->assertJsonPath('data.linked_returns.0.item_count',3);
        $this->getJson("/api/print-documents/sales_return/$documentId")->assertOk()->assertJsonCount(3,'data.content.items')->assertJsonPath('data.content.totals.customer_credit_total',101);
    }

    public function test_invalid_line_rolls_back_without_header_lines_stock_or_voucher(): void
    {
        [$invoice,,$fixtures]=$this->sale();
        $count=DB::table('invoice_returns')->count(); $stock=DB::table('stock')->count();
        $items=collect($fixtures)->values()->map(fn($x)=>['invoice_id'=>$invoice,'product_id'=>$x['product']->id,'batch_id'=>$x['batch']->id,'quantity'=>1])->all();
        $items[2]['quantity']=999;
        $this->postJson('/api/sales-returns',['items'=>$items])->assertUnprocessable();
        $this->assertDatabaseCount('sales_returns',0);
        $this->assertSame($count,DB::table('invoice_returns')->count());
        $this->assertSame($stock,DB::table('stock')->count());
        $this->assertSame(0,DB::table('vouchers')->where('source_type','sales_return')->count());
    }

    private function sale(): array
    {
        $customer=DB::table('customers')->insertGetId(['name'=>'Grouped Customer','phone_num'=>uniqid(),'city'=>'Test']);
        $prices=[5,100,6]; $costs=[1,20,2]; $fixtures=[]; $items=[];
        foreach($prices as $i=>$price){$product=Product::factory()->create(['barcode'=>'GROUP-'.$i.'-'.uniqid()]);$batch=ProductBatch::query()->create(['product_id'=>$product->id,'batch_number'=>'GROUP-'.$i.'-'.uniqid(),'expiry_date'=>now()->addYear(),'quantity'=>10,'purchase_price'=>$costs[$i],'sale_price'=>$price]);$fixtures[]=['product'=>$product,'batch'=>$batch];$items[]=['product_id'=>$product->id,'amount'=>1,'price'=>$price,'discount'=>0];}
        $invoice=$this->postJson('/api/sales-invoices',['customer_id'=>$customer,'date'=>now()->toDateString(),'paid_amount'=>0,'payment_method'=>'cash','items'=>$items])->assertCreated()->json('data.id');
        return[$invoice,$customer,$fixtures];
    }
}
