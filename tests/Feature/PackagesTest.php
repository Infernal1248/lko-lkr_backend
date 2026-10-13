<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PackagesTest extends TestCase
{
    use RefreshDatabase;

    private function data(): array
    {
        return ['name' => 'Новый пакет', 'credits' => 70, 'amount' => 120050, 'active' => true, 'sort_order' => 0];
    }

    public function test_only_admin_can_manage_packages(): void
    {
        $this->getJson('/api/admin/packages')->assertUnauthorized();
        $this->postJson('/api/admin/packages', $this->data())->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/admin/packages')->assertForbidden();
        $this->postJson('/api/admin/packages', $this->data())->assertForbidden();
        $this->putJson('/api/admin/packages/start', $this->data())->assertForbidden();
        $this->assertDatabaseCount('packages', 3);
    }

    public function test_admin_can_create_edit_sort_and_disable(): void
    {
        $this->actingAs(User::factory()->create(['is_support_admin' => true]));
        $key = $this->postJson('/api/admin/packages', $this->data())->assertSuccessful()->json('key');
        $this->getJson('/api/packages')->assertJsonPath('packages.3.key', $key)->assertJsonPath('packages.3.amount', 120050);
        $this->putJson('/api/admin/packages/'.$key, [...$this->data(), 'active' => false])->assertOk();
        $this->getJson('/api/packages')->assertJsonCount(3, 'packages');
        $this->getJson('/api/admin/packages')->assertJsonCount(4);
        $this->putJson('/api/admin/packages/start', [...$this->data(), 'credits' => 0])->assertUnprocessable();
        $this->putJson('/api/admin/packages/start', [...$this->data(), 'amount' => -10])->assertUnprocessable();
        $this->putJson('/api/admin/packages/start', [...$this->data(), 'amount' => 100.5])->assertUnprocessable();
    }

    public function test_reordering_is_atomic_admin_only_and_survives_edits(): void
    {
        $keys = ['business', 'start', 'standard'];
        $this->putJson('/api/admin/packages/order', ['keys' => $keys])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->putJson('/api/admin/packages/order', ['keys' => $keys])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_support_admin' => true]));
        $this->putJson('/api/admin/packages/order', ['keys' => $keys])->assertOk()->assertJsonPath('0.key', 'business');
        $this->getJson('/api/packages')->assertJsonPath('packages.0.key', 'business')->assertJsonPath('packages.1.key', 'start');
        $this->putJson('/api/admin/packages/business', $this->data())->assertOk()->assertJsonPath('sort_order', 1);
        $this->putJson('/api/admin/packages/order', ['keys' => ['start', 'start', 'standard']])->assertUnprocessable();
        $this->putJson('/api/admin/packages/order', ['keys' => ['start', 'missing', 'standard']])->assertConflict();
        $this->putJson('/api/admin/packages/order', ['keys' => ['start', 'standard']])->assertConflict();
        $this->assertSame($keys, Package::orderBy('sort_order')->pluck('key')->all());
    }

    public function test_existing_order_keeps_terms_after_edit_and_disable(): void
    {
        config(['cards.payments_enabled' => true, 'cards.yookassa.shop_id' => 'test', 'cards.yookassa.secret' => 'test', 'cards.yookassa.test' => true]);
        Http::fake(['api.yookassa.ru/*' => fn () => Http::response(['id' => (string) Str::uuid(), 'test' => true, 'confirmation' => ['confirmation_url' => 'https://yookassa.ru/test']])]);
        $user = User::factory()->create();
        $this->actingAs($user);
        $data = ['package' => 'start', 'request_key' => (string) Str::uuid(), 'buyer_type' => 'person', 'buyer_name' => 'Покупатель', 'amount' => 1, 'credits' => 99999];
        $this->postJson('/api/payments', $data)->assertSuccessful()->assertJsonPath('amount', 49000)->assertJsonPath('credits', 25);
        Package::findOrFail('start')->update(['amount' => 200000, 'credits' => 80, 'active' => false]);
        $this->postJson('/api/payments', $data)->assertSuccessful()->assertJsonPath('amount', 49000)->assertJsonPath('credits', 25);
        $this->postJson('/api/payments', [...$data, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        Http::assertSentCount(1);
        Package::findOrFail('start')->update(['active' => true]);
        $this->postJson('/api/payments', [...$data, 'request_key' => (string) Str::uuid()])->assertSuccessful()->assertJsonPath('amount', 200000)->assertJsonPath('credits', 80);
    }
}
