<?php

namespace Tests\Feature;

use App\Models\Consent;
use App\Models\Generation;
use App\Models\User;
use App\Services\CardInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CardAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $user = User::factory()->create(['credits' => 10]);
        Consent::create(['user_id' => $user->id, 'version' => config('cards.consent_version'), 'organization' => ['name' => 'Тест'], 'created_at' => now()]);
        $this->actingAs($user);

        return $user;
    }

    private function generate(string $key = 'ppd-lkr'): string
    {
        return $this->postJson('/api/generations', ['request_key' => (string) Str::uuid(), 'template_key' => $key, 'data' => CardInput::demo(), 'settings' => ['offset_x' => 0, 'offset_y' => 0, 'font_size' => 12, 'fields' => []]])->assertCreated()->assertJsonPath('template_key', $key)->json('id');
    }

    public function test_number_is_assigned_after_generation_without_altering_pdf_or_charging(): void
    {
        $user = $this->user();
        $id = $this->generate();
        $pdf = Generation::find($id)->pdf;
        $this->assertNull(Generation::find($id)->card_number);
        $this->putJson("/api/generations/$id/number", ['card_number' => '0000123456'])->assertOk()->assertJsonPath('card_number', '0000123456');
        $this->assertSame($pdf, Generation::find($id)->pdf);
        $this->assertSame(9, $user->fresh()->credits);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->getJson('/api/generations?q=0000123456')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.card_number', '0000123456');
        $this->assertNotSame('0000123456', Generation::find($id)->getRawOriginal('card_number'));
        $this->putJson("/api/generations/$id/number", ['card_number' => null])->assertOk()->assertJsonPath('assigned_at', null);
    }

    public function test_duplicate_number_is_rejected_within_series_but_allowed_across_series(): void
    {
        $this->user();
        $a = $this->generate();
        $b = $this->generate();
        $c = $this->generate('ppd-lko');
        $this->putJson("/api/generations/$a/number", ['card_number' => '001'])->assertOk();
        $this->putJson("/api/generations/$b/number", ['card_number' => '001'])->assertUnprocessable();
        $this->putJson("/api/generations/$c/number", ['card_number' => '001'])->assertOk();
    }

    public function test_ownership_deleted_documents_and_invalid_numbers_are_enforced(): void
    {
        $owner = $this->user();
        $id = $this->generate();
        $this->user();
        $this->putJson("/api/generations/$id/number", ['card_number' => '123'])->assertNotFound();
        $this->actingAs($owner)->putJson("/api/generations/$id/number", ['card_number' => '1e3'])->assertUnprocessable();
        $this->deleteJson("/api/generations/$id")->assertNoContent();
        $this->putJson("/api/generations/$id/number", ['card_number' => '123'])->assertGone();
    }

    public function test_each_template_has_its_own_calibration_and_unknown_templates_are_rejected(): void
    {
        $this->user();
        $lkr = $this->getJson('/api/calibration/layout?template_key=ppd-lkr')->assertOk()->json();
        $lko = $this->getJson('/api/calibration/layout?template_key=ppd-lko')->assertOk()->json();
        $this->assertNotSame($lkr['fields'][0]['y'], $lko['fields'][0]['y']);
        $this->getJson('/api/calibration/layout?template_key=bad')->assertUnprocessable();
        $this->getJson('/api/calibration/background?template_key=bad')->assertUnprocessable();
        $this->getJson('/api/templates')->assertJsonCount(2);
    }
}
