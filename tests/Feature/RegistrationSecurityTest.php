<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\RegistrationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $username = 'newaccount', string $email = 'test@example.test'): array
    {
        return ['username' => $username, 'email' => $email, 'name' => 'Тест', 'password' => 'Password123456', 'password_confirmation' => 'Password123456', 'terms' => true, 'privacy' => true];
    }

    private function send(string $email = 'test@example.test'): string
    {
        config(['registration.captcha_enabled' => false]);
        $this->postJson('/api/registration/email-code', ['email' => $email])->assertOk();

        $code = '';
        Notification::assertSentOnDemand(RegistrationCode::class, function ($notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        return $code;
    }

    public function test_account_is_created_only_after_code_confirmation_and_shared_email_still_works(): void
    {
        Notification::fake();
        $this->postJson('/api/register', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('email_code');
        $code = $this->send();
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
        $this->postJson('/api/register', [...$this->payload(), 'email_code' => $code])->assertCreated();
        $this->assertNotNull(User::first()->email_verified_at);
        $this->postJson('/api/logout')->assertNoContent();
        $this->postJson('/api/register', [...$this->payload('second'), 'email_code' => $code])->assertUnprocessable();
        $this->travel(61)->seconds();
        $second = $this->send();
        $this->postJson('/api/register', [...$this->payload('second'), 'email_code' => $second])->assertCreated();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_wrong_codes_expiry_email_binding_and_resend_invalidation(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();
        $code = $this->send();
        $this->postJson('/api/register', [...$this->payload(email: 'other@example.test'), 'email_code' => $code])->assertUnprocessable();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/register', [...$this->payload(), 'email_code' => '000000'])->assertUnprocessable();
        }
        $this->travel(61)->seconds();
        $this->postJson('/api/register', [...$this->payload(), 'email_code' => $code])->assertUnprocessable();
        $fresh = $this->send();
        $this->assertNotSame($code, $fresh);
        $this->travel(11)->minutes();
        $this->postJson('/api/register', [...$this->payload(), 'email_code' => $fresh])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_resend_is_allowed_and_cancels_previous_code(): void
    {
        Notification::fake();
        $old = $this->send();
        $this->postJson('/api/registration/email-code', ['email' => 'TEST@example.test'])->assertOk();
        Notification::assertCount(2);
        $this->postJson('/api/register', [...$this->payload(), 'email_code' => $old])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_captcha_requires_success_expected_host_and_fails_closed(): void
    {
        Notification::fake();
        config(['registration.captcha_enabled' => true, 'registration.site_key' => 'public', 'registration.secret_key' => 'secret', 'registration.host' => 'example.test']);
        $this->postJson('/api/registration/email-code', ['email' => 'test@example.test'])->assertUnprocessable();
        foreach ([['status' => 'failed'], ['status' => 'ok', 'host' => 'evil.test'], ['status' => 'ok', 'host' => '']] as $reply) {
            Http::swap(new Factory);
            Http::swap(new Factory);
            Http::fake(['smartcaptcha.cloud.yandex.ru/*' => Http::response($reply)]);
            $this->postJson('/api/registration/email-code', ['email' => 'test@example.test', 'captcha_token' => 'token'])->assertUnprocessable();
        }
        Http::swap(new Factory);
        Http::fake(['smartcaptcha.cloud.yandex.ru/*' => Http::response([], 503)]);
        $this->postJson('/api/registration/email-code', ['email' => 'test@example.test', 'captcha_token' => 'token'])->assertStatus(503);
        Notification::assertNothingSent();
        Http::swap(new Factory);
        Http::fake(['smartcaptcha.cloud.yandex.ru/*' => Http::response(['status' => 'ok', 'host' => 'example.test'])]);
        $this->postJson('/api/registration/email-code', ['email' => 'test@example.test', 'captcha_token' => 'token'])->assertOk();
        Http::assertSent(fn ($request) => $request['secret'] === 'secret' && $request['token'] === 'token');
        Notification::assertCount(1);
        $this->assertArrayNotHasKey('secret_key', $this->getJson('/api/registration/settings')->json());
    }
}
