<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private function payment(): Payment
    {
        config(['cards.payments_enabled' => true, 'cards.yookassa.shop_id' => 'test', 'cards.yookassa.secret' => 'test', 'cards.yookassa.test' => true]);

        return Payment::create(['id' => (string) Str::uuid(), 'user_id' => User::factory()->create()->id, 'request_key' => (string) Str::uuid(), 'package' => 'start', 'credits' => 25, 'amount' => 49000, 'provider_id' => 'provider-test']);
    }

    private function remote(Payment $p): array
    {
        return ['id' => 'provider-test', 'metadata' => ['order_id' => $p->id], 'status' => 'succeeded', 'paid' => true, 'test' => true, 'amount' => ['currency' => 'RUB', 'value' => '490.00']];
    }

    public function test_verified_payment_credits_once_despite_repeated_webhook(): void
    {
        $p = $this->payment();
        Http::fake(['api.yookassa.ru/*' => Http::response($this->remote($p))]);
        $this->postJson('/api/payments/webhook', ['object' => ['id' => 'provider-test']])->assertOk();
        $this->postJson('/api/payments/webhook', ['object' => ['id' => 'provider-test']])->assertOk();
        $this->assertSame(25, User::find($p->user_id)->credits);
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_forged_success_is_ignored_when_provider_says_pending(): void
    {
        $p = $this->payment();
        $remote = $this->remote($p);
        $remote['status'] = 'pending';
        $remote['paid'] = false;
        Http::fake(['api.yookassa.ru/*' => Http::response($remote)]);
        $this->postJson('/api/payments/webhook', ['object' => ['id' => 'provider-test', 'status' => 'succeeded', 'paid' => true]])->assertOk();
        $this->assertSame(0, User::find($p->user_id)->credits);
    }

    public function test_wrong_amount_and_test_mode_are_rejected(): void
    {
        $p = $this->payment();
        $remote = $this->remote($p);
        $remote['amount']['value'] = '1.00';
        Http::fake(['api.yookassa.ru/*' => Http::response($remote)]);
        $this->postJson('/api/payments/webhook', ['object' => ['id' => 'provider-test']])->assertUnprocessable();
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_prices_are_server_owned_and_checkout_is_idempotent(): void
    {
        $p = $this->payment();
        $user = User::find($p->user_id);
        Http::fake(['api.yookassa.ru/*' => Http::response(['id' => 'another-payment', 'test' => true, 'confirmation' => ['confirmation_url' => 'https://yookassa.ru/test']])]);
        $data = ['buyer_type' => 'person', 'buyer_name' => 'Тестовый покупатель', 'package' => 'start', 'request_key' => (string) Str::uuid(), 'amount' => 1, 'credits' => 9999];
        $this->actingAs($user);
        $this->postJson('/api/payments', $data)->assertSuccessful()->assertJsonPath('amount', 49000)->assertJsonPath('credits', 25);
        $this->postJson('/api/payments', $data)->assertOk();
        Http::assertSentCount(1);
    }
}
