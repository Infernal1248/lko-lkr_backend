<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

trait ConfirmsRegistration
{
    private function registerConfirmed(array $data): TestResponse
    {
        return $this->withSession(['registration_verification' => [
            'email' => mb_strtolower(trim($data['email'])), 'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10)->timestamp, 'attempts' => 0,
        ]])->postJson('/api/register', [...$data, 'email_code' => '123456']);
    }
}
