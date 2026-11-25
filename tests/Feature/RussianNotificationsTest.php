<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class RussianNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_confirmation_is_localized_for_registration_and_reset_even_with_english_app_locale(): void
    {
        $passwords = ['password' => 'Password1234', 'password_confirmation' => 'Different1234'];
        App::setLocale('en');
        $this->postJson('/api/register', $passwords + [
            'name' => 'Образцов', 'username' => 'example', 'email' => 'test@example.net',
            'terms' => true, 'privacy' => true,
        ])->assertUnprocessable()->assertJsonPath('errors.password.0', 'Пароли не совпадают.');

        App::setLocale('en');
        $this->postJson('/api/reset-password', $passwords + ['token' => 'test'])
            ->assertUnprocessable()->assertJsonPath('errors.password.0', 'Пароли не совпадают.');
    }

    public function test_validation_summary_and_password_strength_are_localized(): void
    {
        $this->postJson('/api/reset-password', ['password' => 'aaaaaaaaaaaa', 'password_confirmation' => 'aaaaaaaaaaaa'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.token.0', 'Заполните поле «код из ссылки».')
            ->assertJsonPath('errors.password.0', 'Добавьте в пароль хотя бы одну цифру.')
            ->assertJsonPath('message', 'Заполните поле «код из ссылки». (ещё одна ошибка)');
    }

    public function test_framework_errors_are_localized_and_keep_retry_header(): void
    {
        $this->getJson('/api/me')->assertUnauthorized()->assertJsonPath('message', 'Войдите в личный кабинет, чтобы продолжить.');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [])->assertUnprocessable();
        }
        $this->postJson('/api/login', [])->assertTooManyRequests()->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Слишком много запросов. Подождите немного и попробуйте ещё раз.');

        $this->getJson('/api/bonus-email/verify/1/expired')->assertForbidden()
            ->assertJsonPath('message', 'Ссылка недействительна или её срок действия истёк.');
        $this->actingAs(User::factory()->create())->getJson('/api/receipts')->assertForbidden()
            ->assertJsonPath('message', 'У вас нет доступа к этому действию.');
    }
}
