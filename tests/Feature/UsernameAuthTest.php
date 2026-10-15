<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class UsernameAuthTest extends TestCase
{
    use RefreshDatabase;

    private function registration(string $username): array
    {
        return ['username' => $username, 'name' => 'Специалист', 'email' => 'shared@example.test', 'password' => 'Password-12345', 'password_confirmation' => 'Password-12345', 'terms' => true, 'privacy' => true];
    }

    public function test_shared_email_accounts_have_unique_case_insensitive_logins(): void
    {
        $a = $this->postJson('/api/register', $this->registration('  Vektor  '))->assertCreated()->assertJsonPath('username', 'vektor')->json('id');
        $this->postJson('/api/logout');
        $b = $this->postJson('/api/register', $this->registration('rubezh'))->assertCreated()->json('id');
        $this->assertNotSame($a, $b);
        $this->postJson('/api/logout');
        $this->postJson('/api/login', ['identifier' => 'VEKTOR', 'password' => 'Password-12345'])->assertOk()->assertJsonPath('id', $a);
        $this->postJson('/api/logout');
        $this->postJson('/api/login', ['identifier' => 'rubezh', 'password' => 'Password-12345'])->assertOk()->assertJsonPath('id', $b);
        $this->postJson('/api/logout');
        $this->postJson('/api/login', ['identifier' => 'shared@example.test', 'password' => 'Password-12345'])->assertUnprocessable();
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach (['VEKTOR', 'a', 'has space', 'почта', 'a@b.ru'] as $name) {
            $this->postJson('/api/register', $this->registration($name))->assertUnprocessable()->assertJsonValidationErrors('username');
        }
    }

    public function test_existing_user_chooses_username_once_without_losing_data(): void
    {
        $user = User::factory()->create(['email' => 'shared@example.test', 'credits' => 37, 'organization' => ['organization_name' => 'Вектор']]);
        $hash = $user->password;
        $this->actingAs($user);
        $this->postJson('/api/username', ['username' => 'Vektor'])->assertOk()->assertJsonPath('username', 'vektor');
        $this->postJson('/api/username', ['username' => 'another'])->assertConflict();
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame(37, $user->fresh()->credits);
        $this->assertSame('Вектор', $user->fresh()->organization['organization_name']);
        $this->postJson('/api/logout');
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertUnprocessable();
        $this->postJson('/api/login', ['identifier' => 'vektor', 'password' => 'password'])->assertOk()->assertJsonPath('id', $user->id);
    }

    public function test_username_selection_requires_authentication_and_uniqueness(): void
    {
        $this->postJson('/api/username', ['username' => 'newname'])->assertUnauthorized();
        User::factory()->create(['username' => 'taken']);
        $u = User::factory()->create();
        $this->actingAs($u)->postJson('/api/username', ['username' => 'TAKEN'])->assertUnprocessable();
        $this->assertNull($u->fresh()->username);
    }

    public function test_shared_email_reset_tokens_are_isolated_and_sent_to_real_email(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();
        $a = User::factory()->create(['username' => 'vektor', 'email' => 'shared@example.test', 'legacy_email' => null]);
        $b = User::factory()->create(['username' => 'rubezh', 'email' => $a->email, 'legacy_email' => null]);
        $this->postJson('/api/forgot-password', ['identifier' => 'vektor'])->assertOk();
        $this->postJson('/api/forgot-password', ['identifier' => 'rubezh'])->assertOk();
        Notification::assertSentTo($a, ResetPassword::class);
        Notification::assertSentTo($b, ResetPassword::class);
        $this->assertSame($a->email, $a->routeNotificationFor('mail'));
        $this->assertDatabaseCount('password_reset_tokens', 2);
        $tokenA = Notification::sent($a, ResetPassword::class)->first()->token;
        $tokenB = Notification::sent($b, ResetPassword::class)->first()->token;
        $payload = ['account_id' => $b->id, 'token' => $tokenA, 'password' => 'Changed-12345', 'password_confirmation' => 'Changed-12345'];
        $this->postJson('/api/reset-password', $payload)->assertUnprocessable();
        $this->postJson('/api/reset-password', [...$payload, 'account_id' => $a->id])->assertOk();
        $this->assertTrue(Hash::check('password', $b->fresh()->password));
        $this->postJson('/api/reset-password', [...$payload, 'token' => $tokenB])->assertOk();
        $this->postJson('/api/reset-password', [...$payload, 'token' => $tokenB])->assertUnprocessable();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_email_is_not_accepted_for_login_or_requesting_recovery(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();
        $old = User::factory()->create(['email' => 'shared@example.test']);
        $new = User::factory()->create(['username' => 'newaccount', 'email' => $old->email, 'legacy_email' => null]);
        foreach ([null, 'oldaccount'] as $username) {
            $old->update(['username' => $username]);
            $this->postJson('/api/login', ['email' => $old->email, 'password' => 'password'])->assertUnprocessable();
            $this->postJson('/api/login', ['identifier' => $old->email, 'password' => 'password'])->assertUnprocessable();
            $this->assertGuest();
            $this->postJson('/api/forgot-password', ['email' => $old->email])->assertUnprocessable();
            $this->postJson('/api/forgot-password', ['identifier' => $old->email])->assertOk();
        }
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->postJson('/api/login', ['identifier' => '  OLDACCOUNT  ', 'password' => 'password'])->assertOk()->assertJsonPath('id', $old->id);
        // Existing links remain usable; this is token verification, not email login.
        $token = Password::createToken($old);
        $this->postJson('/api/reset-password', ['email' => $old->email, 'token' => $token, 'password' => 'Changed-12345', 'password_confirmation' => 'Changed-12345'])->assertOk();
        $this->assertTrue(Hash::check('password', $new->fresh()->password));
    }

    public function test_migration_preserves_old_tokens_and_accounts(): void
    {
        $migration = require database_path('migrations/2026_10_14_000001_add_account_usernames.php');
        $migration->down();
        $id = DB::table('users')->insertGetId(['name' => 'Старый аккаунт', 'email' => 'old@example.test', 'password' => Hash::make('password'), 'credits' => 25]);
        DB::table('password_reset_tokens')->insert(['email' => 'old@example.test', 'token' => Hash::make('old-token'), 'created_at' => now()]);
        $migration->up();
        $this->assertDatabaseHas('users', ['id' => $id, 'username' => null, 'legacy_email' => 'old@example.test', 'credits' => 25]);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'account:'.$id]);
        $this->postJson('/api/reset-password', ['email' => 'old@example.test', 'token' => 'old-token', 'password' => 'Changed-12345', 'password_confirmation' => 'Changed-12345'])->assertOk();
    }
}
