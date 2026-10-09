<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_mail_is_sent_and_token_cannot_be_reused(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
        Notification::assertSentTo($user, ResetPassword::class);
        $token = Password::createToken($user);
        $input = ['email' => $user->email, 'token' => $token, 'password' => 'New-Password-2026', 'password_confirmation' => 'New-Password-2026'];
        $this->postJson('/api/reset-password', $input)->assertOk();
        $this->assertTrue(Hash::check($input['password'], $user->fresh()->password));
        $this->postJson('/api/reset-password', $input)->assertUnprocessable();
    }

    public function test_forgot_password_does_not_reveal_account_existence(): void
    {
        $this->postJson('/api/forgot-password', ['email' => 'missing@example.test'])->assertOk()->assertJsonPath('message', 'Если аккаунт существует, инструкция отправлена на почту.');
    }
}
