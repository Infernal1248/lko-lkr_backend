<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WelcomeBonusTest extends TestCase
{
    use RefreshDatabase;

    public function test_bonus_requires_login(): void
    {
        $this->postJson('/api/welcome-bonus')->assertUnauthorized();
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_existing_account_claims_once_and_keeps_bonus_status_after_spending(): void
    {
        $user = User::factory()->create(['credits' => 75]);
        $this->actingAs($user)->getJson('/api/me')->assertOk()->assertJsonPath('welcome_bonus_claimed', false);
        $this->postJson('/api/welcome-bonus')->assertOk()->assertJsonPath('credits', 78)->assertJsonPath('welcome_bonus_claimed', true);
        $this->postJson('/api/welcome-bonus')->assertOk()->assertJsonPath('credits', 78);
        $this->assertDatabaseHas('ledger_entries', [
            'user_id' => $user->id, 'amount' => 3, 'balance' => 78,
            'reason' => 'welcome_bonus', 'reference' => 'welcome:'.$user->id,
        ]);
        $user->refresh()->forceFill(['credits' => 0])->save();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('welcome_bonus_claimed', true);
        $this->postJson('/api/welcome-bonus')->assertOk()->assertJsonPath('credits', 0);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->getJson('/api/ledger')->assertOk()->assertJsonPath('data.0.reason', 'welcome_bonus');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_each_account_gets_three_and_cannot_choose_amount_or_recipient(): void
    {
        $a = User::factory()->create(['credits' => 0]);
        $b = User::factory()->create(['email' => $a->email, 'legacy_email' => null, 'credits' => 0]);
        $this->actingAs($a)->postJson('/api/welcome-bonus', ['user_id' => $b->id, 'amount' => 1000])->assertOk()->assertJsonPath('credits', 3);
        $this->assertSame(0, $b->fresh()->credits);
        $this->actingAs($b)->getJson('/api/me')->assertOk()->assertJsonPath('welcome_bonus_claimed', false);
        $this->postJson('/api/welcome-bonus')->assertOk()->assertJsonPath('credits', 3);
        $this->assertSame(3, $a->fresh()->credits);
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_failed_ledger_write_rolls_back_balance_and_allows_retry(): void
    {
        $user = User::factory()->create(['credits' => 20]);
        LedgerEntry::creating(function (LedgerEntry $entry) {
            if ($entry->reason === 'welcome_bonus') {
                throw new \RuntimeException('Simulated ledger failure');
            }
        });
        try {
            $this->actingAs($user)->postJson('/api/welcome-bonus')->assertStatus(500);
            $this->assertSame(20, $user->fresh()->credits);
            $this->assertDatabaseCount('ledger_entries', 0);
        } finally {
            LedgerEntry::flushEventListeners();
        }
        $this->postJson('/api/welcome-bonus')->assertOk()->assertJsonPath('credits', 23);
    }
}
