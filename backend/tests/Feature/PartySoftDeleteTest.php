<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PartySoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::factory()->create([
            'role' => 1,
            'force_change_password' => false,
        ]), 'sanctum');
    }

    public function test_active_customer_appears_in_list(): void
    {
        $id = $this->customer();

        $this->getJson('/api/customers')
            ->assertOk()
            ->assertJsonFragment(['id' => $id, 'name' => 'Soft Delete Customer']);
    }

    public function test_archived_customer_does_not_appear_in_list(): void
    {
        $id = $this->customer(['deleted_at' => now()]);

        $this->getJson('/api/customers')
            ->assertOk()
            ->assertJsonMissing(['id' => $id]);
    }

    public function test_archived_customer_detail_returns_not_found(): void
    {
        $id = $this->customer(['deleted_at' => now()]);

        $this->getJson("/api/customers/{$id}")
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_archived_customer_cannot_be_updated(): void
    {
        $id = $this->customer(['deleted_at' => now()]);

        $this->putJson("/api/customers/{$id}", ['name' => 'Changed'])
            ->assertNotFound();
        $this->assertDatabaseMissing('customers', ['id' => $id, 'name' => 'Changed']);
    }

    public function test_active_provider_appears_in_list(): void
    {
        $id = $this->provider();

        $this->getJson('/api/providers')
            ->assertOk()
            ->assertJsonFragment(['id' => $id, 'name' => 'Soft Delete Provider']);
    }

    public function test_archived_provider_does_not_appear_in_list(): void
    {
        $id = $this->provider(['deleted_at' => now()]);

        $this->getJson('/api/providers')
            ->assertOk()
            ->assertJsonMissing(['id' => $id]);
    }

    public function test_archived_provider_detail_returns_not_found(): void
    {
        $id = $this->provider(['deleted_at' => now()]);

        $this->getJson("/api/providers/{$id}")
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_archived_provider_cannot_be_updated(): void
    {
        $id = $this->provider(['deleted_at' => now()]);

        $this->putJson("/api/providers/{$id}", ['name' => 'Changed'])
            ->assertNotFound();
        $this->assertDatabaseMissing('providers', ['id' => $id, 'name' => 'Changed']);
    }

    private function customer(array $attributes = []): int
    {
        return DB::table('customers')->insertGetId(array_merge([
            'name' => 'Soft Delete Customer',
            'phone_num' => '100',
            'city' => 'Test',
        ], $attributes));
    }

    private function provider(array $attributes = []): int
    {
        return DB::table('providers')->insertGetId(array_merge([
            'name' => 'Soft Delete Provider',
            'phone_num' => '200',
            'city' => 'Test',
        ], $attributes));
    }
}
