<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Product;
use App\Services\AccountingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ErpFinalCompletionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp(); $this->seed();
        $this->admin=User::factory()->create(['role'=>1,'force_change_password'=>false,'branch_id'=>DB::table('branches')->where('code','MAIN')->value('id')]);
        $this->actingAs($this->admin,'sanctum');
    }

    public function test_settlement_allocations_are_persisted_and_drive_exact_ar_aging(): void
    {
        $customer=DB::table('customers')->insertGetId(['name'=>'Allocated customer','phone_num'=>'91001','city'=>'Test']);
        $invoice=DB::table('sales_invoices')->insertGetId(['document_number'=>'SAL-ALLOC-1','customer_id'=>$customer,'user_id'=>$this->admin->id,'total_price'=>100,'discount'=>0,'paid_amount'=>0,'payment_method'=>'cash','profit'=>20,'date'=>now()->subDays(40)->toDateString(),'due_date'=>now()->subDays(35)->toDateString(),'created_at'=>now(),'updated_at'=>now()]);
        $this->postJson('/api/receipt-vouchers',['customer_id'=>$customer,'amount'=>60,'date'=>now()->toDateString(),'payment_method'=>'cash','allocations'=>[['invoice_id'=>$invoice,'amount'=>60]]])->assertCreated();
        $this->assertDatabaseHas('settlement_allocations',['invoice_id'=>$invoice,'amount'=>60]);
        $this->getJson('/api/accounting/reports/ar-aging?party_id='.$customer.'&detail=1')->assertOk()->assertJsonPath('data.0.aging_basis','invoice_allocation')->assertJsonPath('data.0.invoices.0.outstanding_amount',40);
    }

    public function test_cashier_shift_uses_only_immutable_user_and_branch_attribution(): void
    {
        $branchA=DB::table('branches')->where('code','MAIN')->value('id');
        $branchB=DB::table('branches')->insertGetId(['name'=>'Second','code'=>'SECOND','is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $other=User::factory()->create(['role'=>1,'force_change_password'=>false,'branch_id'=>$branchB]);
        $customer=DB::table('customers')->insertGetId(['name'=>'Shift customer','phone_num'=>'91002','city'=>'Test']);
        $shiftA=$this->postJson('/api/cashier-shifts/open',['branch_id'=>$branchA,'opening_cash'=>10])->assertCreated()->json('data.id');
        $this->postJson('/api/receipt-vouchers',['customer_id'=>$customer,'amount'=>25,'date'=>now()->toDateString(),'payment_method'=>'cash'])->assertCreated();
        $this->actingAs($other,'sanctum');
        $shiftB=$this->postJson('/api/cashier-shifts/open',['branch_id'=>$branchB,'opening_cash'=>20])->assertCreated()->json('data.id');
        $this->postJson('/api/receipt-vouchers',['customer_id'=>$customer,'amount'=>40,'date'=>now()->toDateString(),'payment_method'=>'cash'])->assertCreated();
        $this->getJson('/api/cashier-shifts/'.$shiftB)->assertOk()->assertJsonPath('data.expected_cash',60);
        $this->actingAs($this->admin,'sanctum')->getJson('/api/cashier-shifts/'.$shiftA)->assertOk()->assertJsonPath('data.expected_cash',35)->assertJsonPath('data.attribution_complete',true);
    }

    public function test_other_income_reversal_and_bank_reconciliation_do_not_unbalance_ledger(): void
    {
        $branch=DB::table('branches')->where('code','MAIN')->value('id');$account=DB::table('accounts')->where('code','4200')->value('id');
        $id=$this->postJson('/api/other-incomes',['date'=>now()->toDateString(),'branch_id'=>$branch,'account_id'=>$account,'amount'=>30,'payment_method'=>'bank','description'=>'Delivery'])->assertCreated()->json('data.id');
        $line=DB::table('journal_entry_lines as l')->join('journal_entries as e','e.id','=','l.journal_entry_id')->join('accounts as a','a.id','=','l.account_id')->where('a.code','1200')->value('l.id');
        $before=app(AccountingReportService::class)->trialBalance();
        $this->postJson('/api/bank-reconciliation/movements/'.$line.'/reconcile',['external_reference'=>'BANK-1'])->assertOk();
        $this->assertEquals($before,app(AccountingReportService::class)->trialBalance());
        $this->postJson('/api/other-incomes/'.$id.'/cancel',['reason'=>'Duplicate'])->assertOk()->assertJsonPath('data.status','cancelled');
        $this->assertTrue(app(AccountingReportService::class)->trialBalance()['is_balanced']);
    }

    public function test_opening_count_adjustment_and_transfer_preserve_unit_snapshots(): void
    {
        $branch=DB::table('branches')->where('code','MAIN')->value('id');$destination=DB::table('branches')->insertGetId(['name'=>'Unit destination','code'=>'UNIT-DST','is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $product=Product::factory()->create();$box=DB::table('units')->where('name','box')->value('id');
        $this->postJson("/api/products/{$product->id}/units",['unit_id'=>$box,'base_quantity'=>20,'is_purchase_unit'=>true,'is_sale_unit'=>true])->assertCreated();
        $opening=$this->postJson('/api/opening-balances',['branch_id'=>$branch,'effective_date'=>now()->toDateString(),'lines'=>[['type'=>'inventory','product_id'=>$product->id,'batch_number'=>'UNIT-ALL','expiry_date'=>now()->addYear()->toDateString(),'quantity'=>3,'unit_id'=>$box,'unit_cost'=>2,'sale_price'=>3]]])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('opening_balance_lines',['session_id'=>$opening,'entered_quantity'=>3,'conversion_factor_snapshot'=>20,'normalized_base_quantity'=>60]);
        $this->postJson("/api/opening-balances/$opening/validate")->assertOk();$this->postJson("/api/opening-balances/$opening/post")->assertOk();$batch=DB::table('product_batches')->where('batch_number','UNIT-ALL')->value('id');
        $adjustment=$this->postJson('/api/stock-adjustments',['product_id'=>$product->id,'batch_id'=>$batch,'quantity'=>-1,'unit_id'=>$box,'reason'=>'damaged'])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('stock_adjustments',['id'=>$adjustment,'quantity'=>-20,'entered_quantity'=>-1,'conversion_factor_snapshot'=>20]);
        $count=$this->postJson('/api/stock-counts',['branch_id'=>$branch,'scope'=>'selected','product_ids'=>[$product->id]])->assertCreated()->json('data.count.id');
        $this->postJson("/api/stock-counts/$count/post",['lines'=>[['batch_id'=>$batch,'counted_quantity'=>2,'unit_id'=>$box,'reason'=>'count_difference']]])->assertOk();
        $this->assertDatabaseHas('stock_count_lines',['stock_count_id'=>$count,'counted_quantity'=>40,'entered_quantity'=>2,'conversion_factor_snapshot'=>20]);
        $transfer=$this->postJson('/api/stock-transfers',['source_branch_id'=>$branch,'destination_branch_id'=>$destination,'lines'=>[['batch_id'=>$batch,'quantity'=>1,'unit_id'=>$box]]])->assertCreated()->json('data.transfer.id');
        $this->assertDatabaseHas('stock_transfer_lines',['transfer_id'=>$transfer,'quantity'=>20,'entered_quantity'=>1,'conversion_factor_snapshot'=>20]);
    }
}
