<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LastPaymentBuyerTest extends TestCase
{
    use RefreshDatabase;

    private function payment(User $user, array $attributes = []): Payment
    {
        return Payment::create(array_merge([
            'id' => (string) Str::uuid(), 'user_id' => $user->id,
            'request_key' => (string) Str::uuid(), 'package' => 'small',
            'credits' => 25, 'amount' => 49000, 'status' => 'succeeded',
            'paid_at' => now()->subDay(),
            'buyer' => ['type' => 'company', 'name' => 'ООО Пример', 'inn' => '0123456789', 'email' => $user->email],
        ], $attributes));
    }

    public function test_guest_cannot_read_buyer_and_empty_history_returns_null(): void
    {
        $this->getJson('/api/payments/last-buyer')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/payments/last-buyer')->assertOk()->assertExactJson(['buyer' => null]);
    }

    public function test_returns_last_success_by_payment_date_not_creation_date_and_ignores_other_accounts(): void
    {
        $user = User::factory()->create();
        $this->payment($user, ['paid_at' => now(), 'created_at' => now()->subDays(5)]);
        $this->payment($user, ['buyer' => ['type' => 'person', 'name' => 'Более ранняя оплата']]);
        foreach (['pending', 'canceled'] as $status) {
            $this->payment($user, ['status' => $status, 'paid_at' => null]);
        }
        $this->payment(User::factory()->create(), ['paid_at' => now()->addMinute(), 'buyer' => ['type' => 'person', 'name' => 'Чужой']]);
        $this->actingAs($user)->getJson('/api/payments/last-buyer')->assertOk()->assertExactJson([
            'buyer' => ['type' => 'company', 'name' => 'ООО Пример', 'inn' => '0123456789'],
        ]);
    }

    public function test_person_is_restored_without_inn_and_pending_payments_are_not_defaults(): void
    {
        $user = User::factory()->create();
        $this->payment($user, ['status' => 'pending', 'paid_at' => null]);
        $this->actingAs($user)->getJson('/api/payments/last-buyer')->assertOk()->assertJsonPath('buyer', null);
        $this->payment($user, ['buyer' => ['type' => 'person', 'name' => 'Иван Иванов', 'inn' => null]]);
        $this->getJson('/api/payments/last-buyer')->assertOk()->assertExactJson(['buyer' => ['type' => 'person', 'name' => 'Иван Иванов', 'inn' => '']]);
    }
}
