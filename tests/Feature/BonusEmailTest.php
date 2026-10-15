<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\User;
use App\Notifications\VerifyBonusEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class BonusEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_account_must_verify_email_and_link_does_not_credit_or_log_in(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['username' => 'new_user', 'credits' => 0]);
        $this->postJson('/api/bonus-email/send')->assertUnauthorized();
        $this->actingAs($user)->postJson('/api/welcome-bonus')->assertUnprocessable();
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->postJson('/api/bonus-email/send')->assertOk();
        Notification::assertSentTo($user, VerifyBonusEmail::class);
        $mail = Notification::sent($user, VerifyBonusEmail::class)->first()->toMail($user);
        $html = view($mail->view['html'], $mail->viewData)->render();
        $this->assertStringContainsString('Подтвердить почту', $html);
        $this->assertStringContainsString('new_user', $html);
        $text = view($mail->view['text'], $mail->viewData)->render();
        $link = $mail->viewData['verificationUrl'];
        $this->assertStringContainsString($link, $text);
        $this->postJson('/api/bonus-email/send')->assertTooManyRequests();
        $this->postJson('/api/logout')->assertNoContent();
        $this->get($link)->assertOk()->assertSee('Почта подтверждена');
        $this->assertGuest();
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame(0, $user->fresh()->credits);
        $this->get($link)->assertOk();
        $this->actingAs($user->fresh())->postJson('/api/welcome-bonus')->assertOk()->assertJsonPath('credits', 3);
    }

    public function test_invalid_expired_or_changed_email_links_are_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $params = ['id' => $user->id, 'hash' => sha1($user->email)];
        $valid = URL::temporarySignedRoute('bonus-email.verify', now()->addHour(), $params);
        $this->get($valid.'tampered')->assertForbidden();
        $expired = URL::temporarySignedRoute('bonus-email.verify', now()->subMinute(), $params);
        $this->get($expired)->assertForbidden();
        $user->update(['email' => 'changed@example.test']);
        $this->get($valid)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_prior_bonuses_are_migrated_and_remain_used_after_account_deletion(): void
    {
        $user = User::factory()->create(['email' => 'Example@example.test']);
        LedgerEntry::create(['user_id' => $user->id, 'amount' => 3, 'balance' => 3,
            'reason' => 'welcome_bonus', 'reference' => 'welcome:'.$user->id, 'created_at' => now()]);
        $migration = require database_path('migrations/2026_10_15_000002_create_welcome_bonus_emails.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseCount('welcome_bonus_emails', 1);
        $user->delete();
        $other = User::factory()->create(['email' => 'example@example.test', 'credits' => 0]);
        $this->actingAs($other)->getJson('/api/me')->assertOk()->assertJsonPath('welcome_bonus_claimed', true);
        $this->postJson('/api/welcome-bonus')->assertOk()->assertJsonPath('credited', false)->assertJsonPath('credits', 0);
        $this->assertNotNull(DB::table('welcome_bonus_emails')->value('claimed_at'));
    }

    public function test_different_emails_remain_eligible(): void
    {
        foreach (['first@example.test', 'second@example.test'] as $email) {
            $user = User::factory()->create(['email' => $email, 'credits' => 0]);
            $this->actingAs($user)->postJson('/api/welcome-bonus')->assertOk()->assertJsonPath('credited', true)->assertJsonPath('credits', 3);
        }
        $this->assertDatabaseCount('welcome_bonus_emails', 2);
        $this->assertDatabaseCount('ledger_entries', 2);
    }
}
