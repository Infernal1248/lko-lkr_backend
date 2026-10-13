<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use App\Services\NpdQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class NpdBotTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'npd-test-secret-012345678901234567890';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 14)->startOfDay());
        config(['npd.secret' => $this->secret, 'npd.since' => '2026-10-13T17:36:24+07:00', 'cards.yookassa.shop_id' => '123456', 'cards.yookassa.secret' => 'provider-test', 'cards.payments_enabled' => true]);
    }

    private function payment(array $extra = []): Payment
    {
        return Payment::create($extra + [
            'id' => (string) Str::uuid(), 'user_id' => User::factory()->create()->id,
            'request_key' => (string) Str::uuid(), 'provider_id' => (string) Str::uuid(),
            'package' => 'standard', 'credits' => 50, 'amount' => 89000,
            'status' => 'succeeded', 'test_mode' => false, 'receipt_mode' => 'npd_manual',
            'paid_at' => now(), 'buyer' => ['type' => 'person', 'name' => 'Покупатель'],
        ]);
    }

    private function remote(Payment $p, array $extra = []): array
    {
        return $extra + ['id' => $p->provider_id, 'metadata' => ['order_id' => $p->id], 'recipient' => ['account_id' => '123456'], 'status' => 'succeeded', 'paid' => true, 'test' => false, 'amount' => ['value' => '890.00', 'currency' => 'RUB'], 'captured_at' => '2026-10-13T11:00:00.000Z'];
    }

    private function auth(): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->secret]);
    }

    private function receiptBody(Payment $p, string $id = 'receipt-1'): array
    {
        return ['shop_id' => '123456', 'payment_id' => $p->provider_id, 'amount_kopecks' => 89000, 'currency' => 'RUB', 'receipt_id' => $id, 'receipt_url' => 'https://lknpd.nalog.ru/api/v1/receipt/'.config('cards.operator_inn').'/'.$id.'/print'];
    }

    public function test_bearer_is_required_even_for_logged_in_admin_and_disabled_when_empty(): void
    {
        $this->actingAs(User::factory()->create(['is_support_admin' => true]));
        $this->getJson('/api/npd/payments')->assertUnauthorized();
        $this->postJson('/api/npd/receipts', [])->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Bearer wrong'])->getJson('/api/npd/payments')->assertUnauthorized();
        $this->auth()->getJson('/api/npd/payments')->assertOk()->assertExactJson(['payments' => []]);
        config(['npd.secret' => '']);
        $this->getJson('/api/npd/payments')->assertUnauthorized();
    }

    public function test_webhook_creates_snapshot_from_verified_response_at_inclusive_boundary(): void
    {
        $p = $this->payment(['status' => 'pending', 'paid_at' => null]);
        Http::fake(['api.yookassa.ru/*' => Http::response($this->remote($p, ['captured_at' => '2026-10-13T10:36:24Z']))]);
        $this->postJson('/api/payments/webhook', ['object' => ['id' => $p->provider_id]])->assertOk();
        $this->assertNotNull($p->fresh()->npd_payload);
        $this->auth()->getJson('/api/npd/payments')->assertJsonCount(1, 'payments');
        Http::assertSentCount(1);
        $this->assertSame(50, User::find($p->user_id)->credits);
    }

    public function test_bad_shop_amount_and_missing_capture_time_never_enter_queue(): void
    {
        $p = $this->payment();
        foreach ([['recipient' => ['account_id' => 'wrong']], ['amount' => ['value' => '1.00', 'currency' => 'RUB']], ['captured_at' => null]] as $invalid) {
            $p->update(['npd_deferred_until' => null]);
            Http::fake(['api.yookassa.ru/*' => Http::response($this->remote($p, $invalid))]);
            $this->auth()->getJson('/api/npd/payments')->assertExactJson(['payments' => []]);
            $this->assertNull($p->fresh()->npd_payload);
        }
    }

    public function test_queue_verifies_and_freezes_contract_without_marking_processed(): void
    {
        $p = $this->payment();
        Http::fake(['api.yookassa.ru/*' => Http::response($this->remote($p))]);
        $first = $this->auth()->getJson('/api/npd/payments')->assertOk()
            ->assertJsonPath('payments.0.shop_id', '123456')->assertJsonPath('payments.0.test', false)
            ->assertJsonPath('payments.0.amount_kopecks', 89000)->assertJsonPath('payments.0.buyer.type', 'PERSON')
            ->assertJsonPath('payments.0.paid_at', '2026-10-13T11:00:00+00:00')->json();
        $p->refresh()->update(['credits' => 999, 'buyer' => ['type' => 'person', 'name' => 'Другой']]);
        $this->getJson('/api/npd/payments')->assertExactJson($first);
        Http::assertSentCount(1);
        $this->assertNull($p->fresh()->receipt_recorded_at);
    }

    public function test_test_old_manual_and_pending_payments_are_excluded(): void
    {
        $this->payment(['test_mode' => true]);
        $this->payment(['paid_at' => '2026-10-12 12:00:00']);
        $this->payment(['receipt_recorded_at' => now(), 'receipt_url' => 'https://lknpd.nalog.ru/manual']);
        $this->payment(['status' => 'pending']);
        $this->payment(['receipt_mode' => '54fz']);
        $p = $this->payment(); // Late webhook for an old actual payment.
        Http::fake(['api.yookassa.ru/*' => Http::response($this->remote($p, ['captured_at' => '2026-10-13T10:36:23Z']))]);
        $this->auth()->getJson('/api/npd/payments')->assertExactJson(['payments' => []]);
        $this->assertTrue($p->fresh()->npd_excluded);
    }

    public function test_wrong_provider_data_is_deferred_and_does_not_block_other_payments(): void
    {
        $bad = $this->payment();
        $good = $this->payment();
        Http::fake([
            'api.yookassa.ru/v3/payments/'.$bad->provider_id => Http::response($this->remote($bad, ['test' => true])),
            'api.yookassa.ru/v3/payments/'.$good->provider_id => Http::response($this->remote($good)),
        ]);
        $this->auth()->getJson('/api/npd/payments')->assertJsonCount(1, 'payments')->assertJsonPath('payments.0.payment_id', $good->provider_id);
        $this->assertNotNull($bad->fresh()->npd_deferred_until);
        $this->assertNull($bad->fresh()->npd_payload);
    }

    public function test_company_and_defer_command(): void
    {
        $p = $this->payment(['buyer' => ['type' => 'company', 'inn' => '1234567890', 'name' => 'ООО Пример']]);
        app(NpdQueue::class)->freeze($p, $this->remote($p));
        $this->auth()->getJson('/api/npd/payments')->assertJsonPath('payments.0.buyer', ['type' => 'COMPANY', 'inn' => '1234567890', 'name' => 'ООО Пример']);
        $this->artisan('npd:defer', ['payment_id' => $p->provider_id])->assertSuccessful();
        $this->getJson('/api/npd/payments')->assertExactJson(['payments' => []]);
        $this->artisan('npd:defer', ['payment_id' => $p->provider_id, '--hours' => '0'])->assertSuccessful();
        $this->getJson('/api/npd/payments')->assertJsonCount(1, 'payments');
    }

    public function test_callback_is_idempotent_conflicts_are_rejected_and_owner_sees_receipt(): void
    {
        $p = $this->payment();
        app(NpdQueue::class)->freeze($p, $this->remote($p));
        $body = $this->receiptBody($p);
        $this->auth()->postJson('/api/npd/receipts', [...$body, 'amount_kopecks' => 1])->assertConflict();
        $this->postJson('/api/npd/receipts', [...$body, 'shop_id' => 'other'])->assertNotFound();
        $this->postJson('/api/npd/receipts', [...$body, 'receipt_url' => 'https://example.org/receipt'])->assertUnprocessable();
        $this->postJson('/api/npd/receipts', $body)->assertNoContent();
        $recorded = $p->fresh()->receipt_recorded_at->toISOString();
        $this->travel(1)->hours();
        $this->postJson('/api/npd/receipts', $body)->assertNoContent();
        $this->assertSame($recorded, $p->fresh()->receipt_recorded_at->toISOString());
        $this->postJson('/api/npd/receipts', $this->receiptBody($p, 'different'))->assertConflict();
        $this->getJson('/api/npd/payments')->assertExactJson(['payments' => []]);
        $this->actingAs(User::find($p->user_id))->getJson('/api/payments')->assertJsonPath('data.0.receipt_url', $body['receipt_url']);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_same_receipt_cannot_be_assigned_to_two_payments_or_overwrite_manual_receipt(): void
    {
        $a = $this->payment();
        $b = $this->payment();
        foreach ([$a, $b] as $p) {
            app(NpdQueue::class)->freeze($p, $this->remote($p));
        }
        $this->auth()->postJson('/api/npd/receipts', $this->receiptBody($a))->assertNoContent();
        $this->postJson('/api/npd/receipts', $this->receiptBody($b))->assertConflict();
        $b->update(['receipt_url' => 'https://lknpd.nalog.ru/manual', 'receipt_recorded_at' => now()]);
        $this->postJson('/api/npd/receipts', $this->receiptBody($b, 'new'))->assertConflict();
    }

    public function test_limit_is_one_hundred_and_callback_frees_slot(): void
    {
        $owner = User::factory()->create();
        for ($i = 0; $i < 101; $i++) {
            $p = $this->payment(['user_id' => $owner->id]);
            app(NpdQueue::class)->freeze($p, $this->remote($p, ['captured_at' => now()->subMinutes(101 - $i)->toIso8601String()]));
        }
        $r = $this->auth()->getJson('/api/npd/payments')->assertJsonCount(100, 'payments');
        $first = Payment::where('provider_id', $r->json('payments.0.payment_id'))->firstOrFail();
        $this->postJson('/api/npd/receipts', $this->receiptBody($first))->assertNoContent();
        $this->getJson('/api/npd/payments')->assertJsonCount(100, 'payments');
    }
}
