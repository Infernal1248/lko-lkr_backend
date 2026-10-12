<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushNotifications;
use App\Services\WebPushSender;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;
use Tests\TestCase;

class PushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $keys = VAPID::createVapidKeys();
        config(['push.enabled' => true, 'push.public_key' => $keys['publicKey'], 'push.private_key' => $keys['privateKey']]);
    }

    private function data(string $suffix = 'one'): array
    {
        return ['endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$suffix, 'keys' => ['p256dh' => VAPID::createVapidKeys()['publicKey'], 'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')]];
    }

    private function sub(User $u): PushSubscription
    {
        $d = $this->data((string) $u->id);

        return PushSubscription::create(['user_id' => $u->id, 'endpoint_hash' => hash('sha256', $d['endpoint']), 'subscription' => $d]);
    }

    public function test_subscription_auth_encryption_ownership_validation_and_logout(): void
    {
        $this->getJson('/api/push/settings')->assertUnauthorized();
        $u = User::factory()->create();
        $d = $this->data();
        $this->actingAs($u)->postJson('/api/push/subscriptions', $d)->assertCreated();
        $this->assertStringNotContainsString($d['endpoint'], DB::table('push_subscriptions')->value('subscription'));
        $this->getJson('/api/push/settings')->assertJsonMissing(['private_key' => config('push.private_key')]);
        $this->actingAs(User::factory()->create())->postJson('/api/push/subscriptions', $d)->assertStatus(409);
        $this->deleteJson('/api/push/subscriptions', ['endpoint' => $d['endpoint']])->assertNoContent();
        $this->assertDatabaseCount('push_subscriptions', 1);
        foreach (['https://127.0.0.1/private', 'https://fcm.googleapis.com.evil.test/x', 'https://user@fcm.googleapis.com/x'] as $url) {
            $this->postJson('/api/push/subscriptions', [...$d, 'endpoint' => $url])->assertUnprocessable();
        }
        $this->actingAs($u)->postJson('/api/logout', ['push_endpoint' => $d['endpoint']])->assertNoContent();
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_support_routes_notify_only_correct_recipients_and_failure_preserves_message(): void
    {
        $u = User::factory()->create();
        $admin = User::factory()->create(['is_support_admin' => true]);
        $us = $this->sub($u);
        $as = $this->sub($admin);
        $this->mock(WebPushSender::class)->shouldReceive('send')->once()->withArgs(fn ($subs, $payload) => $subs->pluck('id')->all() === [$as->id] && ! str_contains(json_encode($payload), 'secret'))->andReturn([$as->id => 201]);
        $id = $this->actingAs($u)->postJson('/api/support', ['subject' => 'secret', 'body' => 'secret'])->assertCreated()->json('id');
        $this->mock(WebPushSender::class)->shouldReceive('send')->once()->withArgs(fn ($subs, $payload) => $subs->pluck('id')->all() === [$us->id] && $payload['url'] === '/?push=support&ticket='.$id)->andThrow(new \RuntimeException('offline'));
        $this->actingAs($admin)->postJson('/api/support/'.$id.'/messages', ['body' => 'Ответ'])->assertCreated();
        $this->assertDatabaseCount('support_messages', 2);
    }

    public function test_payment_push_is_sent_once_for_verified_success(): void
    {
        config(['cards.payments_enabled' => true, 'cards.yookassa.shop_id' => 'test', 'cards.yookassa.secret' => 'test', 'cards.yookassa.test' => true]);
        $admin = User::factory()->create(['is_support_admin' => true]);
        $s = $this->sub($admin);
        $p = Payment::create(['id' => (string) Str::uuid(), 'user_id' => User::factory()->create()->id, 'request_key' => (string) Str::uuid(), 'test_mode' => true, 'package' => 'start', 'credits' => 25, 'amount' => 49000, 'provider_id' => 'provider-test']);
        Http::fake(['api.yookassa.ru/*' => Http::response(['id' => 'provider-test', 'metadata' => ['order_id' => $p->id], 'status' => 'succeeded', 'paid' => true, 'test' => true, 'amount' => ['currency' => 'RUB', 'value' => '490.00']])]);
        $this->mock(WebPushSender::class)->shouldReceive('send')->once()->withArgs(fn ($subs, $payload) => $subs->pluck('id')->all() === [$s->id] && $payload['title'] === 'Тестовая оплата прошла')->andReturn([$s->id => 201]);
        $this->postJson('/api/payments/webhook', ['object' => ['id' => 'provider-test']])->assertOk();
        $this->postJson('/api/payments/webhook', ['object' => ['id' => 'provider-test']])->assertOk();
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_transport_encrypts_and_does_not_follow_redirects(): void
    {
        $s = $this->sub(User::factory()->create());
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(301, ['Location' => 'https://127.0.0.1/'])]));
        $stack->push(Middleware::history($history));
        $sender = new WebPushSender(new Client(['handler' => $stack]));
        $this->assertSame([$s->id => 301], $sender->send(collect([$s]), ['body' => 'private plaintext']));
        $this->assertCount(1, $history);
        $this->assertSame('aes128gcm', $history[0]['request']->getHeaderLine('Content-Encoding'));
        $this->assertStringNotContainsString('private plaintext', (string) $history[0]['request']->getBody());
    }

    public function test_expired_subscription_is_removed_but_temporary_failure_is_retained(): void
    {
        $a = $this->sub(User::factory()->create());
        $b = $this->sub(User::factory()->create());
        $this->mock(WebPushSender::class)->shouldReceive('send')->once()->andReturn([$a->id => 410, $b->id => 503]);
        $this->assertSame(0, app(PushNotifications::class)->send(collect([$a, $b]), ['body' => 'test']));
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $a->id]);
        $this->assertDatabaseHas('push_subscriptions', ['id' => $b->id]);
    }

    public function test_key_command_preserves_app_key_and_existing_vapid_keys(): void
    {
        $original = app()->environmentPath();
        $file = app()->environmentFile();
        $dir = sys_get_temp_dir().'/push-keys-'.bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir.'/'.$file, "APP_KEY=keep-existing-key\nVAPID_PUBLIC_KEY=\nVAPID_PRIVATE_KEY=\n");
        try {
            app()->useEnvironmentPath($dir);
            $this->artisan('push:keys')->assertSuccessful();
            $content = file_get_contents($dir.'/'.$file);
            $this->assertStringContainsString('APP_KEY=keep-existing-key', $content);
            $this->assertMatchesRegularExpression('/VAPID_PUBLIC_KEY=[A-Za-z0-9_-]{87}/', $content);
            $this->artisan('push:keys')->assertFailed();
            $this->assertSame($content, file_get_contents($dir.'/'.$file));
        } finally {
            app()->useEnvironmentPath($original);
            unlink($dir.'/'.$file);
            rmdir($dir);
        }
    }

    public function test_browser_can_decrypt_complete_push_record(): void
    {
        // Decode independently with OpenSSL/HKDF, as an RFC 8291 receiver would.
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $details = openssl_pkey_get_details($private);
        $public = "\x04".$details['ec']['x'].$details['ec']['y'];
        $auth = random_bytes(16);
        $encode = fn ($bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $s = $this->sub(User::factory()->create());
        $s->subscription = ['endpoint' => 'https://web.push.apple.com/test', 'keys' => ['p256dh' => $encode($public), 'auth' => $encode($auth)]];
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(201)]));
        $stack->push(Middleware::history($history));
        $payload = ['title' => 'ЛКО / ЛКР', 'body' => 'Пробное уведомление'];
        $sender = new WebPushSender(new Client(['handler' => $stack]));
        $this->assertSame([$s->id => 201], $sender->send(collect([$s]), $payload));
        $record = (string) $history[0]['request']->getBody();
        $salt = substr($record, 0, 16);
        $keyLength = ord($record[20]);
        $serverKey = substr($record, 21, $keyLength);
        $this->assertSame(65, $keyLength);
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$serverKey;
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        $shared = openssl_pkey_derive(openssl_pkey_get_public($pem), $private, 32);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$public.$serverKey, $auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $encrypted = substr($record, 21 + $keyLength);
        $plain = openssl_decrypt(substr($encrypted, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($encrypted, -16));
        $this->assertNotFalse($plain);
        $unpadded = rtrim($plain, "\0");
        $this->assertSame("\x02", substr($unpadded, -1), 'Final record delimiter required by RFC 8188 must be present.');
        $this->assertSame($payload, json_decode(substr($unpadded, 0, -1), true, 512, JSON_THROW_ON_ERROR));
    }
}
