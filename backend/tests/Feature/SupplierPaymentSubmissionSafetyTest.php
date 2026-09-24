<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AgingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierPaymentSubmissionSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $provider;
    private int $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $branch = (int) DB::table('branches')->where('code', 'MAIN')->value('id');
        $this->admin = User::factory()->create(['role' => 1, 'branch_id' => $branch, 'force_change_password' => false]);
        $this->actingAs($this->admin, 'sanctum');
        $this->provider = DB::table('providers')->insertGetId([
            'name' => 'Payment Safety Supplier', 'phone_num' => 'PAY-SAFE', 'city' => 'Test',
        ]);
        $this->invoice = DB::table('purchase_invoices')->insertGetId([
            'document_number' => 'PUR-PAY-SAFE', 'provider_id' => $this->provider, 'user_id' => $this->admin->id,
            'branch_id' => $branch, 'amount' => 100, 'descount' => 0, 'invoice_discount' => 0,
            'total_before_discount' => 100, 'total_after_discount' => 100, 'date' => now()->toDateString(),
            'due_date' => now()->toDateString(), 'status' => 'posted', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_valid_allocated_payment_is_created_and_reduces_ap_exactly_once(): void
    {
        $response = $this->postJson('/api/payment-vouchers', $this->payload(50))->assertCreated();
        $paymentId = (int) $response->json('data.id');

        $this->assertSame(1, DB::table('payment_vouchers')->where('id', $paymentId)->count());
        $this->assertSame(1, DB::table('settlement_allocations')->where(['settlement_type' => 'payment', 'settlement_id' => $paymentId])->count());
        $this->assertEquals(50, DB::table('settlement_allocations')->where(['settlement_type' => 'payment', 'settlement_id' => $paymentId])->sum('amount'));
        $this->assertSame(1, DB::table('vouchers')->where(['source_type' => 'payment', 'source_id' => $paymentId])->count());

        $aging = app(AgingService::class)->report(Request::create('/', 'GET', ['party_id' => $this->provider, 'detail' => true]), 'supplier');
        $this->assertEquals(50, $aging['data'][0]['total_outstanding']);
        $this->assertEquals(50, $aging['data'][0]['invoices'][0]['settled_amount']);
    }

    public function test_over_allocation_returns_422_and_rolls_back_every_payment_effect(): void
    {
        $this->postJson('/api/payment-vouchers', $this->payload(101, 150))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allocations');

        $this->assertSame(0, DB::table('payment_vouchers')->where('provider_id', $this->provider)->count());
        $this->assertSame(0, DB::table('settlement_allocations')->where('invoice_id', $this->invoice)->count());
        $this->assertSame(0, DB::table('vouchers')->where('source_type', 'payment')->count());
    }

    public function test_repeated_stale_payload_cannot_create_a_second_payment(): void
    {
        $payload = $this->payload(60, 60);
        $this->postJson('/api/payment-vouchers', $payload)->assertCreated();
        $this->postJson('/api/payment-vouchers', $payload)->assertUnprocessable();

        $this->assertSame(1, DB::table('payment_vouchers')->where('provider_id', $this->provider)->count());
        $this->assertSame(1, DB::table('settlement_allocations')->where('invoice_id', $this->invoice)->count());
        $this->assertSame(1, DB::table('vouchers')->where('source_type', 'payment')->count());
    }

    public function test_frontend_locks_submit_and_makes_success_and_errors_exclusive(): void
    {
        $page = file_get_contents(base_path('../frontend/src/app/pages/FriendlyVoucherPages.tsx'));
        $api = file_get_contents(base_path('../frontend/src/api/finance.ts'));

        $this->assertStringContainsString('if (submitLock.current) return;', $page);
        $this->assertStringContainsString('disabled={submitting}', $page);
        $this->assertStringContainsString('submitAttempt.current !== attempt', $page);
        $this->assertStringContainsString('setError(null);', $page);
        $this->assertStringContainsString('setAlloc({});', $page);
        $this->assertStringContainsString('amount: ""', $page);
        $this->assertStringContainsString('setSuccess("");', $page);
        $this->assertStringContainsString('signal?:AbortSignal', $api);
        $this->assertStringContainsString("'payment-vouchers',payload,signal", $api);
    }

    private function payload(float $allocation, float $amount = 50): array
    {
        return [
            'provider_id' => $this->provider, 'amount' => $amount, 'date' => now()->toDateString(),
            'payment_method' => 'cash', 'allocations' => [['invoice_id' => $this->invoice, 'amount' => $allocation]],
        ];
    }
}
