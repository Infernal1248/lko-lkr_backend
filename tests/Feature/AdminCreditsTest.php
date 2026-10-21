<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminCreditsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_search_and_grant(): void
    {
        $user = User::factory()->create(['credits' => 2]);
        $payload = ['user_id' => $user->id, 'amount' => 25, 'request_key' => (string) Str::uuid()];
        $this->getJson('/api/admin/accounts')->assertUnauthorized();
        $this->postJson('/api/admin/credits', $payload)->assertUnauthorized();
        $this->actingAs($user)->getJson('/api/admin/accounts')->assertForbidden();
        $this->postJson('/api/admin/credits', $payload)->assertForbidden();
        $this->assertSame(2, $user->fresh()->credits);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_grant_is_additive_audited_and_idempotent(): void
    {
        $admin = User::factory()->create(['is_support_admin' => true]);
        $user = User::factory()->create(['credits' => 7]);
        $payload = ['user_id' => $user->id, 'amount' => 25, 'request_key' => (string) Str::uuid()];
        $this->actingAs($admin)->postJson('/api/admin/credits', $payload)->assertOk()->assertJsonPath('user.credits', 32);
        $this->postJson('/api/admin/credits', $payload)->assertOk()->assertJsonPath('user.credits', 32);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertDatabaseHas('ledger_entries', ['user_id' => $user->id, 'amount' => 25, 'balance' => 32, 'reason' => 'manual', 'reference' => 'manual:'.$admin->id.':'.$payload['request_key']]);
        $this->postJson('/api/admin/credits', [...$payload, 'amount' => 50])->assertConflict();
        $this->postJson('/api/admin/credits', [...$payload, 'user_id' => $admin->id])->assertConflict();
        $this->postJson('/api/admin/credits', [...$payload, 'request_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('user.credits', 57);
        $this->assertSame(57, $user->fresh()->credits);
        $this->assertDatabaseCount('payments', 0);
        $this->actingAs($user)->getJson('/api/ledger')->assertOk()->assertJsonPath('data.0.reason', 'manual');
    }

    public function test_search_only_returns_account_selection_fields(): void
    {
        $admin = User::factory()->create(['is_support_admin' => true]);
        $user = User::factory()->create(['name' => 'Иван Образцов', 'email' => 'find@example.test']);
        foreach (['Образцов', 'find@example.test'] as $search) {
            $response = $this->actingAs($admin)->getJson('/api/admin/accounts?search='.urlencode($search))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $user->id);
            $this->assertEqualsCanonicalizing(['id', 'name', 'email', 'username', 'credits', 'employees_count', 'registry_count'], array_keys($response->json('data.0')));
        }
    }

    public function test_account_counts_are_scoped_exclude_deleted_registry_and_do_not_expose_employee_data(): void
    {
        $admin = User::factory()->create(['is_support_admin' => true]);
        $user = User::factory()->create();
        foreach ([$user, $user, $admin] as $owner) {
            Card::create(['user_id' => $owner->id, 'template_key' => 'ppd-lkr', 'data' => ['surname' => 'СекретнаяФамилия']]);
        }
        foreach ([[$user, null], [$user, now()], [$admin, null]] as [$owner, $deleted]) {
            Generation::create([
                'id' => (string) Str::uuid(), 'user_id' => $owner->id,
                'request_key' => (string) Str::uuid(), 'fingerprint' => 'test',
                'template_key' => 'ppd-lkr', 'template_version' => '1',
                'snapshot' => ['data' => ['surname' => 'СекретнаяФамилия']], 'deleted_at' => $deleted,
            ]);
        }
        $response = $this->actingAs($admin)->getJson('/api/admin/accounts')->assertOk()->assertJsonPath('total', 2);
        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertEquals(2, $rows[$user->id]['employees_count']);
        $this->assertEquals(1, $rows[$user->id]['registry_count']);
        $this->assertEquals(1, $rows[$admin->id]['employees_count']);
        $this->assertEquals(1, $rows[$admin->id]['registry_count']);
        $this->assertStringNotContainsString('СекретнаяФамилия', json_encode($response->json(), JSON_UNESCAPED_UNICODE));
    }

    public function test_debit_is_audited_idempotent_and_cannot_make_balance_negative(): void
    {
        $admin = User::factory()->create(['is_support_admin' => true]);
        $user = User::factory()->create(['credits' => 10]);
        $payload = ['user_id' => $user->id, 'amount' => -7, 'request_key' => (string) Str::uuid()];
        $this->actingAs($admin)->postJson('/api/admin/credits', $payload)->assertOk()->assertJsonPath('user.credits', 3);
        $this->postJson('/api/admin/credits', $payload)->assertOk()->assertJsonPath('user.credits', 3);
        $this->assertDatabaseHas('ledger_entries', ['user_id' => $user->id, 'amount' => -7, 'balance' => 3, 'reason' => 'manual']);
        $this->postJson('/api/admin/credits', [...$payload, 'amount' => 7])->assertConflict();
        $this->postJson('/api/admin/credits', [...$payload, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame(3, $user->fresh()->credits);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->postJson('/api/admin/credits', [...$payload, 'amount' => -3, 'request_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('user.credits', 0);
    }

    public function test_invalid_amounts_and_missing_accounts_cannot_change_balance(): void
    {
        $admin = User::factory()->create(['is_support_admin' => true, 'credits' => 0]);
        $this->actingAs($admin);
        $payload = ['user_id' => $admin->id, 'amount' => 1, 'request_key' => (string) Str::uuid()];
        foreach ([0, -1, 1.5, 100001, 'abc'] as $amount) {
            $this->postJson('/api/admin/credits', [...$payload, 'amount' => $amount])->assertUnprocessable();
        }
        $this->postJson('/api/admin/credits', [...$payload, 'user_id' => 999999])->assertUnprocessable();
        $this->postJson('/api/admin/credits', [...$payload, 'request_key' => 'bad'])->assertUnprocessable();
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(0, $admin->fresh()->credits);
        $this->postJson('/api/admin/credits', $payload)->assertOk()->assertJsonPath('user.credits', 1);
    }
}
