<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Consent;
use App\Models\Generation;
use App\Models\PrinterProfile;
use App\Models\User;
use App\Services\CardInput;
use App\Services\PdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CardLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function source(): array
    {
        $user = User::factory()->create(['credits' => 0]);
        Consent::create(['user_id' => $user->id, 'version' => config('cards.consent_version'), 'organization' => [], 'created_at' => now()]);
        $this->actingAs($user);
        $settings = ['offset_x' => 0, 'offset_y' => 0, 'font_size' => 12, 'fields' => []];
        $employee = Card::create(['user_id' => $user->id, 'template_key' => 'ppd-lkr', 'data' => CardInput::demo()]);
        $source = Generation::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'employee_id' => $employee->id,
            'request_key' => (string) Str::uuid(), 'fingerprint' => 'test',
            'template_key' => 'ppd-lkr', 'template_version' => '3.0',
            'snapshot' => ['template_key' => 'ppd-lkr', 'data' => CardInput::demo(), 'settings' => $settings],
            'registry' => ['birth_place' => 'Красноярск'], 'pdf' => base64_encode('Original PDF'),
            'card_number' => '000123', 'card_number_hash' => hash_hmac('sha256', '000123', config('app.key')),
        ]);
        $this->mock(PdfRenderer::class)->shouldReceive('render')->andReturnUsing(fn ($type, $data, $settings) => json_encode([$type, $data, $settings]));

        return [$user, $source, $employee, $settings];
    }

    public function test_free_type_correction_changes_layout_preserves_data_and_records_history(): void
    {
        [$user, $source, $employee, $settings] = $this->source();
        $settings['offset_y'] = 2;
        $profile = PrinterProfile::create(['user_id' => $user->id, 'name' => 'Принтер ЛКО', 'template_key' => 'ppd-lko', 'settings' => $settings, 'calibrated_at' => now()]);
        $before = $source->snapshot;
        $this->postJson('/api/generations/'.$source->id.'/lifecycle', ['action' => 'type', 'template_key' => 'ppd-lko', 'profile_id' => $profile->id, 'data_rights' => true, 'data' => ['surname' => 'Чужая фамилия']])->assertOk()->assertJsonPath('template_key', 'ppd-lko')->assertJsonPath('card_number', null);
        $source->refresh();
        $this->assertSame($before['data'], $source->snapshot['data']);
        $this->assertSame($settings, $source->snapshot['settings']);
        $this->assertSame('000123', $source->corrections[0]['previous_number']);
        $this->assertSame($before['data'], json_decode(base64_decode($source->pdf), true)[1]);
        $this->assertSame('ppd-lko', json_decode(base64_decode($source->pdf), true)[0]);
        $this->assertSame(0, $user->fresh()->credits);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('generations', 1);
    }

    public function test_replacement_costs_one_generation_is_atomic_idempotent_and_preserves_original(): void
    {
        [$user, $source, $employee] = $this->source();
        $user->forceFill(['credits' => 1])->save();
        $snapshot = $source->snapshot;
        $created = $source->created_at->toISOString();
        $payload = ['action' => 'replace', 'issue_date' => '2026-11-17', 'request_key' => (string) Str::uuid(), 'data_rights' => true];
        $new = $this->postJson('/api/generations/'.$source->id.'/lifecycle', $payload)->assertCreated()->assertJsonPath('source_generation_id', $source->id)->assertJsonPath('card_number', null)->json('id');
        $this->postJson('/api/generations/'.$source->id.'/lifecycle', $payload)->assertOk()->assertJsonPath('id', $new);
        $this->postJson('/api/generations/'.$source->id.'/lifecycle', [...$payload, 'issue_date' => '2026-11-18'])->assertConflict();
        $this->postJson('/api/generations/'.$source->id.'/lifecycle', [...$payload, 'request_key' => (string) Str::uuid()])->assertConflict();
        $source->refresh();
        $this->assertSame($snapshot, $source->snapshot);
        $this->assertSame('Original PDF', base64_decode($source->pdf));
        $this->assertSame('000123', $source->card_number);
        $this->assertSame($created, $source->created_at->toISOString());
        $this->assertNotNull($source->inactive_at);
        $replacement = Generation::findOrFail($new);
        $this->assertSame($employee->id, $replacement->employee_id);
        $this->assertSame($snapshot['data']['surname'], $replacement->snapshot['data']['surname']);
        $this->assertSame('2026-11-17', $replacement->snapshot['data']['issue_date']);
        $this->get('/api/generations/'.$new.'/download')->assertOk();
        $this->get('/api/generations/'.$source->id.'/download')->assertOk();
        $this->assertSame('2026-11-17', $replacement->fresh()->snapshot['data']['issue_date']);
        $this->putJson('/api/generations/'.$source->id.'/number', ['card_number' => '000999'])->assertConflict();
        $this->putJson('/api/generations/'.$new.'/number', ['card_number' => '000123'])->assertUnprocessable();
        $this->putJson('/api/generations/'.$new.'/number', ['card_number' => '000456'])->assertOk();
        $this->getJson('/api/cards')->assertOk()->assertJsonCount(2, '0.generations');
        $this->getJson('/api/generations?employee_id='.$employee->id)->assertOk()->assertJsonPath('total', 2);
        $this->assertSame(0, $user->fresh()->credits);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertDatabaseHas('ledger_entries', ['user_id' => $user->id, 'amount' => -1, 'balance' => 0, 'reason' => 'generation', 'reference' => 'generation:'.$new]);
        $this->assertDatabaseCount('generations', 2);
    }

    public function test_reissue_requires_number_and_rights_and_is_owner_scoped(): void
    {
        [$user, $source] = $this->source();
        $payload = ['action' => 'replace', 'issue_date' => '2026-11-17', 'request_key' => (string) Str::uuid(), 'data_rights' => true];
        $this->postJson('/api/generations/'.$source->id.'/lifecycle', [...$payload, 'data_rights' => false])->assertUnprocessable();
        $source->update(['card_number' => null, 'card_number_hash' => null]);
        $this->postJson('/api/generations/'.$source->id.'/lifecycle', $payload)->assertUnprocessable();
        $this->assertNull($source->fresh()->inactive_at);
        $other = User::factory()->create();
        Consent::create(['user_id' => $other->id, 'version' => config('cards.consent_version'), 'organization' => [], 'created_at' => now()]);
        $this->actingAs($other)->postJson('/api/generations/'.$source->id.'/lifecycle', $payload)->assertNotFound();
        $this->getJson('/api/cards')->assertExactJson([]);
        $this->getJson('/api/generations?employee_id='.$source->employee_id)->assertJsonPath('total', 0);
        $this->assertDatabaseCount('generations', 1);
    }

    public function test_issue_date_cannot_be_changed_independently_of_reissue(): void
    {
        [$user, $source] = $this->source();
        $snapshot = $source->snapshot;
        $created = $source->created_at->toISOString();
        $this->postJson('/api/generations/'.$source->id.'/lifecycle', ['action' => 'date', 'issue_date' => '2025-01-02', 'data_rights' => true])->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->assertSame($snapshot, $source->fresh()->snapshot);
        $this->assertSame($created, $source->fresh()->created_at->toISOString());
        $this->assertSame('000123', $source->fresh()->card_number);
        $this->assertDatabaseCount('generations', 1);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_type_correction_requires_correct_calibrated_owned_profile(): void
    {
        [$user, $source, $employee, $settings] = $this->source();
        $other = User::factory()->create();
        foreach ([[$other->id, 'ppd-lko', now()], [$user->id, 'ppd-lkr', now()], [$user->id, 'ppd-lko', null]] as [$ownerId, $type, $calibrated]) {
            $profile = PrinterProfile::create(['user_id' => $ownerId, 'name' => 'Недоступный профиль', 'template_key' => $type, 'settings' => $settings, 'calibrated_at' => $calibrated]);
            $this->postJson('/api/generations/'.$source->id.'/lifecycle', ['action' => 'type', 'template_key' => 'ppd-lko', 'profile_id' => $profile->id, 'data_rights' => true])->assertUnprocessable();
        }
        $this->assertSame('ppd-lkr', $source->fresh()->template_key);
        $this->assertSame('Original PDF', base64_decode($source->fresh()->pdf));
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_insufficient_balance_does_not_create_replacement_or_retire_source(): void
    {
        [$user, $source] = $this->source();
        $this->postJson('/api/generations/'.$source->id.'/lifecycle', ['action' => 'replace', 'issue_date' => '2026-11-17', 'request_key' => (string) Str::uuid(), 'data_rights' => true])->assertStatus(402);
        $this->assertNull($source->fresh()->inactive_at);
        $this->assertSame(0, $user->fresh()->credits);
        $this->assertDatabaseCount('generations', 1);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_failed_render_does_not_retire_original_or_create_replacement(): void
    {
        [$user, $source] = $this->source();
        $user->forceFill(['credits' => 1])->save();
        $this->mock(PdfRenderer::class)->shouldReceive('render')->andThrow(new \RuntimeException('PDF failed'));
        $this->postJson('/api/generations/'.$source->id.'/lifecycle', ['action' => 'replace', 'issue_date' => '2026-11-17', 'request_key' => (string) Str::uuid(), 'data_rights' => true])->assertStatus(500);
        $this->assertNull($source->fresh()->inactive_at);
        $this->assertSame(1, $user->fresh()->credits);
        $this->assertDatabaseCount('generations', 1);
        $this->assertDatabaseCount('ledger_entries', 0);
    }
}
