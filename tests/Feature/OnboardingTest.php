<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_account_needs_introduction_and_dismissal_is_persistent_and_private(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user)->getJson('/api/me')->assertOk()->assertJsonPath('user.onboarding_seen_at', null);
        $this->postJson('/api/onboarding/seen', ['user_id' => $other->id])->assertNoContent();
        $seen = $user->fresh()->onboarding_seen_at;
        $this->assertNotNull($seen);
        $this->assertNull($other->fresh()->onboarding_seen_at);
        $this->travel(1)->day();
        $this->postJson('/api/onboarding/seen')->assertNoContent();
        $this->assertTrue($seen->equalTo($user->fresh()->onboarding_seen_at));
    }

    public function test_guests_cannot_mark_introduction_seen(): void
    {
        $this->postJson('/api/onboarding/seen')->assertUnauthorized();
    }
}
