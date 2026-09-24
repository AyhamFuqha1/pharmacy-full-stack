<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\Product;
use App\Models\User;
use App\Services\AccountingPostingService;
use App\Services\AccountingReportService;
use App\Services\ProfitabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProfitabilityModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $product;
    private int $batch;
    private int $sale;

    protected function setUp(): void
    {
        parent::setUp(); $this->seed();
        $this->admin=User::factory()->create(['role'=>1,'force_change_password'=>false]); $this->actingAs($this->admin,'sanctum');
        AccountingPeriod::create(['name'=>'Profitability','period_year'=>(int)now()->format('Y'),'period_month'=>(int)now()->format('m'),'start_date'=>now()->startOfMonth()->toDateString(),'end_date'=>now()->endOfMonth()->toDateString()]);
        $customer=DB::table('customers')->insertGetId(['name'=>'Profit Customer','phone_num'=>'PROFIT-1','city'=>'Test']);
        $product=Product::factory()->create(); $this->product=$product->id;
        $this->batch=DB::table('product_batches')->insertGetId(['product_id'=>$product->id,'batch_number'=>'P-LOT','expiry_date'=>now()->addYear(),'purchase_price'=>4,'sale_price'=>12,'quantity'=>100,'created_at'=>now(),'updated_at'=>now()]);
        $this->sale=DB::table('sales_invoices')->insertGetId(['document_number'=>'SAL-PROFIT-1','customer_id'=>$customer,'user_id'=>$this->admin->id,'total_price'=>90,'discount'=>10,'paid_amount'=>90,'payment_method'=>'cash','profit'=>50,'date'=>now()->toDateString(),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('prod_sales')->insert(['sales_invoice_id'=>$this->sale,'batch_id'=>$this->batch,'amount'=>10,'price'=>10,'discount'=>0,'cost_price'=>4,'line_total'=>100,'invoice_discount_share'=>10,'final_total'=>90,'profit'=>50,'created_at'=>now(),'updated_at'=>now()]);
        app(AccountingPostingService::class)->postSalesInvoice($this->sale);
    }

    public function test_discounted_sale_uses_historical_revenue_and_cost_not_current_batch_cost(): void
    {
        DB::table('product_batches')->where('id',$this->batch)->update(['purchase_price'=>99]);
        $p=app(ProfitabilityService::class)->product($this->product,now()->startOfMonth()->toDateString(),now()->toDateString());
        $this->assertSame(90.0,$p['net_sales_revenue']); $this->assertSame(40.0,$p['cogs']); $this->assertSame(50.0,$p['gross_profit']); $this->assertSame(55.56,$p['gross_margin_percent']);
    }

    public function test_reduced_credit_non_restock_return_is_separated_and_reconciles_to_gl(): void
    {
        $return=DB::table('invoice_returns')->insertGetId(['document_number'=>'SRET-PROFIT-1','invoice_id'=>$this->sale,'product_id'=>$this->product,'batch_id'=>$this->batch,'quantity'=>2,'price'=>9,'original_returnable_unit_amount'=>9,'customer_credit_unit_amount'=>7,'return_to_inventory'=>false,'created_by'=>$this->admin->id,'created_at'=>now(),'updated_at'=>now()]);
        app(AccountingPostingService::class)->postSalesReturn($return);
        $p=app(ProfitabilityService::class)->product($this->product);
        $this->assertSame(72.0,$p['net_sales_revenue']); $this->assertSame(32.0,$p['cogs']); $this->assertSame(40.0,$p['gross_profit']);
        $this->assertSame(4.0,$p['return_deductions_other_income']); $this->assertSame(8.0,$p['non_restocked_return_loss']);
        $income=app(AccountingReportService::class)->incomeStatement();
        $this->assertSame(72.0,$income['net_sales_revenue']); $this->assertSame(4.0,$income['other_income']); $this->assertSame(32.0,$income['cogs']); $this->assertSame(8.0,$income['inventory_adjustments']); $this->assertSame(36.0,$income['net_income']); $this->assertTrue($income['gl_reconciliation']['reconciled']);
    }

    public function test_cancelled_sales_are_excluded_from_product_profitability(): void
    {
        DB::table('sales_invoices')->where('id',$this->sale)->update(['deleted_at'=>now()]);
        $p=app(ProfitabilityService::class)->product($this->product);
        $this->assertSame(0.0,$p['sold_quantity']); $this->assertSame(0.0,$p['net_sales_revenue']); $this->assertSame(0.0,$p['cogs']);
    }

    public function test_full_restock_return_reverses_revenue_and_cogs(): void
    {
        $return=DB::table('invoice_returns')->insertGetId(['document_number'=>'SRET-FULL-1','invoice_id'=>$this->sale,'product_id'=>$this->product,'batch_id'=>$this->batch,'quantity'=>10,'price'=>9,'original_returnable_unit_amount'=>9,'customer_credit_unit_amount'=>9,'return_to_inventory'=>true,'created_by'=>$this->admin->id,'created_at'=>now(),'updated_at'=>now()]);
        app(AccountingPostingService::class)->postSalesReturn($return);
        $p=app(ProfitabilityService::class)->product($this->product);
        $this->assertSame(0.0,$p['net_sales_revenue']); $this->assertSame(0.0,$p['cogs']); $this->assertSame(0.0,$p['gross_profit']); $this->assertSame(0.0,$p['non_restocked_return_loss']);
    }

    public function test_expense_and_other_income_change_gl_net_profit_without_changing_product_gross_profit(): void
    {
        $expenseAccount=DB::table('accounts')->where('code','5200')->value('id');
        $expense=DB::table('expenses')->insertGetId(['document_number'=>'EXP-PROFIT-1','expense_account_id'=>$expenseAccount,'amount'=>5,'type'=>'cash','payment_method'=>'cash','date'=>now()->toDateString(),'description'=>'Profit test','status'=>'posted','user_id'=>$this->admin->id,'created_at'=>now(),'updated_at'=>now()]);
        app(AccountingPostingService::class)->postExpense($expense);
        $incomeAccount=DB::table('accounts')->where('code','4200')->value('id');
        $income=DB::table('other_incomes')->insertGetId(['document_number'=>'OIN-PROFIT-1','date'=>now()->toDateString(),'account_id'=>$incomeAccount,'amount'=>7,'payment_method'=>'cash','description'=>'Profit test','status'=>'posted','user_id'=>$this->admin->id,'created_at'=>now(),'updated_at'=>now()]);
        app(AccountingPostingService::class)->postOtherIncome($income);
        $statement=app(AccountingReportService::class)->incomeStatement();
        $this->assertSame(7.0,$statement['other_income']); $this->assertSame(5.0,$statement['operating_expenses']); $this->assertSame(52.0,$statement['net_income']); $this->assertTrue($statement['gl_reconciliation']['reconciled']);
        $this->assertSame(50.0,app(ProfitabilityService::class)->product($this->product)['gross_profit']);
    }

    public function test_profitability_endpoints_require_profit_permission(): void
    {
        $this->getJson("/api/products/{$this->product}/profitability")->assertOk()->assertJsonPath('data.gross_profit',50);
        $this->getJson('/api/reports/product-profitability')->assertOk()->assertJsonPath('data.0.product_id',$this->product);
        $operatorRole=DB::table('roles')->where('name','Sales & Inventory Operator')->value('id');
        $operator=User::factory()->create(['role'=>$operatorRole,'force_change_password'=>false]); $this->actingAs($operator,'sanctum');
        $this->getJson("/api/products/{$this->product}/profitability")->assertForbidden();
        $this->getJson('/api/reports/product-profitability')->assertForbidden();
        $this->getJson('/api/accounting/reports/income-statement')->assertForbidden();
    }
}
