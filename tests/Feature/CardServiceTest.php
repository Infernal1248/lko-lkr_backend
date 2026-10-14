<?php

namespace Tests\Feature;

use App\Models\Consent;
use App\Models\Generation;
use App\Models\PrinterProfile;
use App\Models\User;
use App\Services\CardInput;
use App\Services\PdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CardServiceTest extends TestCase
{
    use RefreshDatabase;

    private function account(int $credits = 3): User
    {
        $user = User::factory()->create(['credits' => $credits]);
        Consent::create(['user_id' => $user->id, 'version' => config('cards.consent_version'), 'organization' => ['name' => 'Тест'], 'created_at' => now()]);

        PrinterProfile::create(['user_id' => $user->id, 'name' => 'Тестовый HP', 'template_key' => 'ppd-lkr', 'settings' => ['offset_x' => 0, 'offset_y' => 0, 'font_size' => 12, 'fields' => []], 'calibrated_at' => now()]);

        return $user;
    }

    private function payload(): array
    {
        return ['data_rights' => true, 'profile_id' => PrinterProfile::query()->value('id'), 'template_key' => 'ppd-lkr', 'data' => CardInput::demo(), 'settings' => ['offset_x' => 0, 'offset_y' => 0, 'font_size' => 12, 'fields' => []], 'request_key' => (string) Str::uuid()];
    }

    public function test_pdf_generation_is_idempotent_and_download_free(): void
    {
        $u = $this->account();
        $data = $this->payload();
        $this->actingAs($u);
        $first = $this->postJson('/api/generations', $data)->assertCreated();
        $this->postJson('/api/generations', $data)->assertCreated()->assertJsonPath('id', $first['id']);
        $this->assertSame(2, $u->fresh()->credits);
        $this->get('/api/generations/'.$first['id'].'/download')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(2, $u->fresh()->credits);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertStringNotContainsString('Образцов', DB::table('generations')->value('snapshot'));
        $data['data']['surname'] = 'Другой';
        $this->postJson('/api/generations', $data)->assertConflict();
    }

    public function test_balance_and_consent_are_enforced(): void
    {
        $u = $this->account(0);
        $this->actingAs($u)->postJson('/api/generations', $this->payload())->assertStatus(402);
        $this->actingAs(User::factory()->create())->postJson('/api/generations', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('generations', 0);
    }

    public function test_failure_does_not_charge(): void
    {
        $u = $this->account();
        $this->mock(PdfRenderer::class)->shouldReceive('render')->andThrow(new \RuntimeException('Failure'));
        $this->actingAs($u)->postJson('/api/generations', $this->payload())->assertStatus(500);
        $this->assertSame(3, $u->fresh()->credits);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('generations', 0);
    }

    public function test_font_limits_and_long_lines_are_enforced(): void
    {
        $u = $this->account();
        $d = $this->payload();
        $d['settings']['font_size'] = 9;
        $this->actingAs($u)->postJson('/api/generations', $d)->assertUnprocessable();
        $d = $this->payload();
        $d['data']['surname'] = str_repeat('Ш', 70);
        $this->postJson('/api/generations', $d)->assertUnprocessable();
        $this->assertSame(3, $u->fresh()->credits);
    }

    public function test_users_cannot_access_other_users_documents_or_drafts(): void
    {
        $owner = $this->account();
        $this->actingAs($owner);
        $g = $this->postJson('/api/generations', $this->payload())->assertCreated();
        $card = $this->postJson('/api/cards', $this->payload())->assertSuccessful();
        $this->actingAs($this->account())->get('/api/generations/'.$g['id'].'/download')->assertNotFound();
        $this->deleteJson('/api/cards/'.$card['id'])->assertNotFound();
        $this->getJson('/api/generations')->assertJsonPath('total', 0);
    }

    public function test_search_and_download_remain_available_until_manual_deletion(): void
    {
        $this->actingAs($this->account());
        $g = $this->postJson('/api/generations', $this->payload())->assertCreated();
        $this->getJson('/api/generations?q='.urlencode('образц'))->assertJsonPath('total', 1);
        $this->getJson('/api/generations?q=other')->assertJsonPath('total', 0);
        $this->travel(400)->days();
        $this->getJson('/api/generations/'.$g['id'].'/download')->assertOk();
        $this->deleteJson('/api/generations/'.$g['id'])->assertNoContent();
        $this->getJson('/api/generations/'.$g['id'].'/download')->assertStatus(410);
        $this->assertNull(Generation::find($g['id'])->snapshot);
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_calibration_never_uses_user_supplied_personal_data(): void
    {
        $renderer = $this->mock(PdfRenderer::class);
        $renderer->shouldReceive('render')->once()->with('ppd-lkr', CardInput::demo(), ['offset_x' => 0, 'offset_y' => 0, 'font_size' => 12, 'fields' => []], false)->andReturn('%PDF-test');
        $d = $this->payload();
        $d['data']['surname'] = 'Настоящий';
        $this->actingAs($this->account())->postJson('/api/calibration/pdf', $d)->assertOk();
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_registration_login_and_logout(): void
    {
        $this->postJson('/api/register', ['username' => 'test_user', 'name' => 'Тестовый пользователь', 'email' => 'test@example.test', 'password' => 'LongPassword123', 'password_confirmation' => 'LongPassword123', 'terms' => true, 'privacy' => true])->assertCreated();
        $this->getJson('/api/me')->assertOk();
        $this->postJson('/api/logout')->assertNoContent();
        $this->postJson('/api/login', ['identifier' => 'test_user', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/login', ['identifier' => 'test_user', 'password' => 'LongPassword123'])->assertOk();
    }
}
