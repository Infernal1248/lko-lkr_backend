<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Consent;
use App\Models\Generation;
use App\Models\User;
use App\Services\CardInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcessingRequirementsTest extends TestCase
{
    use RefreshDatabase;

    private function account(): User
    {
        $user = User::factory()->create();
        Consent::create(['user_id' => $user->id, 'version' => config('cards.consent_version'), 'organization' => ['name' => 'Заказчик'], 'created_at' => now()]);
        $this->actingAs($user);

        return $user;
    }

    private function card(User $user): Card
    {
        return Card::create(['user_id' => $user->id, 'template_key' => 'ppd-lkr', 'data' => CardInput::demo(), 'registry' => ['birth_date' => '1991-02-03', 'birth_place' => 'Частное место']]);
    }

    private function generation(User $user): Generation
    {
        return Generation::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'request_key' => (string) Str::uuid(), 'fingerprint' => str_repeat('a', 64), 'template_key' => 'ppd-lkr', 'template_version' => '3.0', 'snapshot' => ['data' => CardInput::demo()], 'registry' => ['birth_date' => '1991-02-03'], 'pdf' => base64_encode('private-document'), 'card_number' => '123', 'card_number_hash' => str_repeat('b', 64)]);
    }

    public function test_card_deletion_records_minimal_encrypted_evidence_and_exports_it(): void
    {
        $user = $this->account();
        $card = $this->card($user);
        $this->deleteJson('/api/cards/'.$card->id)->assertNoContent();
        $this->assertDatabaseMissing('cards', ['id' => $card->id]);
        $event = DB::table('deletion_events')->sole();
        $this->assertStringNotContainsString('Образцов', $event->evidence);
        $evidence = json_decode(Crypt::decryptString($event->evidence), true);
        $this->assertContains('Образцов Иван Иванович', $evidence['subjects']);
        $this->assertContains('Дата и место рождения', $evidence['categories']);
        $this->assertFalse($evidence['all_copies_destroyed']);
        $this->assertStringNotContainsString('1991-02-03', json_encode($evidence));
        $this->assertStringNotContainsString('Частное место', json_encode($evidence, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, Artisan::call('privacy:deletions', ['--id' => $event->id]));
        $export = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($evidence, $export['evidence']);
        $this->assertSame($event->id, $export['event_id']);
        $this->assertSame(0, Artisan::call('privacy:deletions'));
        $this->assertStringNotContainsString('Образцов', Artisan::output());
    }

    public function test_generation_deletion_is_idempotent_and_does_not_remove_employee(): void
    {
        $user = $this->account();
        $card = $this->card($user);
        $generation = $this->generation($user);
        $generation->update(['employee_id' => $card->id]);
        $this->deleteJson('/api/generations/'.$generation->id)->assertNoContent();
        $this->deleteJson('/api/generations/'.$generation->id)->assertNoContent();
        $this->assertDatabaseCount('deletion_events', 1);
        foreach (['pdf', 'snapshot', 'registry', 'card_number', 'card_number_hash', 'employee_id'] as $field) {
            $this->assertNull($generation->fresh()->$field);
        }
        $this->assertDatabaseHas('cards', ['id' => $card->id]);
        $evidence = json_decode(Crypt::decryptString(DB::table('deletion_events')->value('evidence')), true);
        $this->assertContains('PDF карточки', $evidence['categories']);
        $this->assertContains('Номер карточки', $evidence['categories']);
        $this->assertStringNotContainsString('private-document', json_encode($evidence));
    }

    public function test_foreign_account_cannot_delete_records_or_create_evidence(): void
    {
        $owner = $this->account();
        $card = $this->card($owner);
        $generation = $this->generation($owner);
        $this->account();
        $this->deleteJson('/api/cards/'.$card->id)->assertNotFound();
        $this->deleteJson('/api/generations/'.$generation->id)->assertNotFound();
        $this->assertDatabaseCount('deletion_events', 0);
        $this->assertNotNull($generation->fresh()->pdf);
        $this->assertDatabaseHas('cards', ['id' => $card->id]);
    }

    public function test_failed_evidence_write_rolls_back_both_deletion_paths(): void
    {
        $owner = $this->account();
        $card = $this->card($owner);
        $generation = $this->generation($owner);
        DB::unprepared("CREATE TRIGGER fail_evidence BEFORE INSERT ON deletion_events BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        $this->deleteJson('/api/cards/'.$card->id)->assertStatus(500);
        $this->deleteJson('/api/generations/'.$generation->id)->assertStatus(500);
        $this->assertDatabaseHas('cards', ['id' => $card->id]);
        $this->assertNotNull($generation->fresh()->pdf);
        $this->assertNull($generation->fresh()->deleted_at);
        $this->assertDatabaseCount('deletion_events', 0);
    }

    public function test_old_confirmation_does_not_accept_new_terms_and_submitted_version_is_checked(): void
    {
        $user = User::factory()->create();
        Consent::create(['user_id' => $user->id, 'version' => '2026-10-11.1', 'organization' => ['name' => 'Заказчик'], 'created_at' => now()]);
        $this->actingAs($user)->getJson('/api/me')->assertOk()->assertJsonPath('consented', false);
        $this->getJson('/api/cards')->assertForbidden();
        $data = ['accepted' => true, 'organization' => 'Заказчик'];
        $this->postJson('/api/consent', $data)->assertUnprocessable();
        $this->postJson('/api/consent', $data + ['version' => '2026-10-11.1'])->assertUnprocessable();
        $this->assertDatabaseCount('consents', 1);
        $this->postJson('/api/consent', $data + ['version' => config('cards.consent_version')])->assertOk();
        $this->getJson('/api/me')->assertJsonPath('consented', true);
        $this->getJson('/api/cards')->assertOk();
        $this->assertFileExists(resource_path('legal/processing-'.config('cards.consent_version').'.txt'));
    }

    public function test_export_rejects_unknown_or_invalid_identifier(): void
    {
        $this->artisan('privacy:deletions', ['--id' => '../invalid'])->assertFailed();
        $this->artisan('privacy:deletions', ['--id' => (string) Str::uuid()])->assertFailed();
    }
}
