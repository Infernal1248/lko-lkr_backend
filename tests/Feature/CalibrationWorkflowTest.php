<?php

namespace Tests\Feature;

use App\Models\Consent;
use App\Models\User;
use App\Services\PdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CalibrationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): array
    {
        return ['offset_x' => 0, 'offset_y' => 0, 'font_size' => 12, 'fields' => []];
    }

    public function test_server_computes_inverse_individual_and_repeated_corrections(): void
    {
        $this->actingAs(User::factory()->create());
        $result = $this->postJson('/api/calibration/apply', ['settings' => $this->settings(), 'measurements' => ['surname' => ['x' => 2, 'y' => 1]]])->assertOk()->assertJsonPath('fields.surname.x', -2)->assertJsonPath('fields.surname.y', -1)->assertJsonPath('fields.director.x', 0);
        $this->postJson('/api/calibration/apply', ['settings' => $result->json(), 'measurements' => ['surname' => ['x' => -.5, 'y' => .2]]])->assertOk()->assertJsonPath('fields.surname.x', -1.5)->assertJsonPath('fields.surname.y', -1.2);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_page_overflow_correction_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/calibration/apply', ['settings' => $this->settings(), 'measurements' => ['issue_date' => ['x' => 0, 'y' => -40]]])->assertUnprocessable();
    }

    public function test_incomplete_input_can_be_previewed_without_filling_missing_names(): void
    {
        $user = User::factory()->create();
        Consent::create(['user_id' => $user->id, 'version' => config('cards.consent_version'), 'organization' => ['name' => 'Тест'], 'created_at' => now()]);
        $this->mock(PdfRenderer::class)->shouldReceive('preview')->once()->withArgs(fn ($key, $data, $settings) => $data['name'] === 'И' && $data['surname'] === '' && $data['issue_date'] === '')->andReturn('PNG');
        $this->actingAs($user)->postJson('/api/preview', ['template_key' => 'ppd-lkr', 'data' => ['name' => 'И'], 'settings' => $this->settings()])->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertDatabaseCount('generations', 0);
    }

    public function test_partial_input_cannot_generate_a_paid_card(): void
    {
        $user = User::factory()->create(['credits' => 1]);
        Consent::create(['user_id' => $user->id, 'version' => config('cards.consent_version'), 'organization' => ['name' => 'Тест'], 'created_at' => now()]);
        $this->actingAs($user)->postJson('/api/generations', ['template_key' => 'ppd-lkr', 'data' => ['name' => 'И'], 'settings' => $this->settings(), 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame(1, $user->fresh()->credits);
    }

    public function test_calibration_layout_is_authenticated_and_demo_only(): void
    {
        $this->getJson('/api/calibration/layout')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/calibration/layout')->assertOk()->assertJsonCount(6, 'fields')->assertJsonPath('fields.0.text', 'Образцов')->assertJsonPath('crop.2', 120);
    }

    public function test_trial_sheet_is_a4_but_printed_data_remains_a5_and_free(): void
    {
        $this->actingAs($user = User::factory()->create(['credits' => 3]));
        foreach (['ppd-lkr', 'ppd-lko'] as $key) {
            foreach ([true => [210, 297], false => [210, 148]] as $proof => [$width, $height]) {
                $response = $this->postJson('/api/calibration/pdf', ['template_key' => $key, 'proof' => (bool) $proof, 'settings' => $this->settings()]);
                $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
                preg_match('/\/MediaBox\s*\[0(?:\.0+)? 0(?:\.0+)? ([0-9.]+) ([0-9.]+)\]/', $response->getContent(), $box);
                $this->assertCount(3, $box);
                $this->assertEqualsWithDelta($width, (float) $box[1] * 25.4 / 72, 0.01);
                $this->assertEqualsWithDelta($height, (float) $box[2] * 25.4 / 72, 0.01);
            }
        }
        $this->assertSame(3, $user->fresh()->credits);
        $this->assertDatabaseCount('generations', 0);
    }
}
