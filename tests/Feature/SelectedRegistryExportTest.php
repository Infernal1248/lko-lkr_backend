<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Consent;
use App\Models\Generation;
use App\Models\User;
use App\Services\CardInput;
use App\Services\RegistryExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\TestCase;

class SelectedRegistryExportTest extends TestCase
{
    use RefreshDatabase;

    private function account(): User
    {
        $user = User::factory()->create(['credits' => 0]);
        Consent::create(['user_id' => $user->id, 'version' => config('cards.consent_version'), 'organization' => [], 'created_at' => now()]);

        return $user;
    }

    private function employee(User $user): Card
    {
        return Card::create(['user_id' => $user->id, 'template_key' => 'ppd-lkr', 'data' => CardInput::demo()]);
    }

    private function generation(User $user, Card $employee, array $attributes = []): Generation
    {
        return Generation::create(array_merge([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'employee_id' => $employee->id,
            'request_key' => (string) Str::uuid(), 'fingerprint' => 'test',
            'template_key' => 'ppd-lkr', 'template_version' => '3.0',
            'snapshot' => ['data' => CardInput::demo()], 'pdf' => base64_encode('PDF'),
        ], $attributes));
    }

    public function test_pdf_and_excel_include_only_selected_registry_rows(): void
    {
        $user = $this->account();
        $a = $this->employee($user);
        $b = $this->employee($user);
        $notSelected = $this->employee($user);
        $other = $this->account();
        $foreign = $this->employee($other);
        $expectedIds = [];
        for ($i = 0; $i < 31; $i++) {
            $expectedIds[] = $this->generation($user, $a)->id;
        }
        $expectedIds[] = $this->generation($user, $b, ['inactive_at' => now()])->id;
        $this->generation($user, $a, ['deleted_at' => now()]);
        $this->generation($user, $notSelected);
        $this->generation($user, $a);
        $this->generation($other, $foreign);
        $export = $this->mock(RegistryExport::class);
        foreach (['pdf', 'xlsx'] as $format) {
            $export->shouldReceive($format)->once()->withArgs(function ($items, $organization) use ($expectedIds) {
                $this->assertCount(32, $items);
                foreach ($items as $item) {
                    $this->assertContains($item->id, $expectedIds);
                }

                return true;
            })->andReturn('export');
            $this->actingAs($user)->postJson('/api/registry/export/'.$format, ['generation_ids' => $expectedIds])->assertOk()->assertContent('export');
        }
        $this->assertSame(0, $user->fresh()->credits);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_selection_cannot_include_foreign_deleted_missing_duplicate_or_empty_registry_rows(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = $this->account();
        $mine = $this->employee($user);
        $other = $this->account();
        $foreign = $this->employee($other);
        $mine = $this->generation($user, $mine);
        $foreign = $this->generation($other, $foreign);
        $deleted = $this->generation($user, $this->employee($user), ['deleted_at' => now()]);
        $this->actingAs($user);
        foreach ([[], [$foreign->id], [$mine->id, $foreign->id], [(string) Str::uuid()], [$mine->id, $mine->id], [$deleted->id]] as $ids) {
            $this->postJson('/api/registry/export/pdf', ['generation_ids' => $ids])->assertUnprocessable();
        }
        $this->postJson('/api/registry/export/xlsx')->assertUnprocessable();
        $this->getJson('/api/registry/export/pdf?generation_ids[]=not-a-uuid')->assertUnprocessable();
    }

    public function test_period_filters_complete_registry_and_both_export_formats_in_utc(): void
    {
        $user = $this->account();
        $employee = $this->employee($user);
        $expected = [];
        foreach (array_merge(['2026-11-15T17:00:00Z', '2026-11-16T16:59:59Z'], array_fill(0, 31, '2026-11-16T10:00:00Z')) as $created) {
            $expected[] = $this->generation($user, $employee, ['created_at' => $created])->id;
        }
        $this->generation($user, $employee, ['created_at' => '2026-11-15T16:59:59Z']);
        $this->generation($user, $employee, ['created_at' => '2026-11-16T17:00:00Z']);
        $other = $this->account();
        $this->generation($other, $this->employee($other), ['created_at' => '2026-11-16T10:00:00Z']);
        $query = http_build_query(['created_from' => '2026-11-16T00:00:00+07:00', 'created_before' => '2026-11-17T00:00:00+07:00', 'q' => 'Образцов']);
        $this->actingAs($user)->getJson('/api/generations')->assertOk()->assertJsonPath('total', 35)->assertJsonCount(35, 'data')->assertJsonMissingPath('last_page');
        $this->getJson('/api/generations?'.$query)->assertOk()->assertJsonPath('total', 33)->assertJsonCount(33, 'data');
        $export = $this->mock(RegistryExport::class);
        foreach (['pdf', 'xlsx'] as $format) {
            $export->shouldReceive($format)->once()->withArgs(function ($items) use ($expected) {
                $this->assertEqualsCanonicalizing($expected, $items->pluck('id')->all());

                return true;
            })->andReturn('export');
            $this->get('/api/registry/export/'.$format.'?'.$query)->assertOk()->assertContent('export');
        }
        $this->getJson('/api/generations?created_from=2026-11-17&created_before=2026-11-16')->assertUnprocessable();
        $this->getJson('/api/registry/export/pdf?created_from=invalid')->assertUnprocessable();
    }

    public function test_employees_are_returned_together_without_pagination(): void
    {
        $user = $this->account();
        for ($i = 0; $i < 35; $i++) {
            $this->employee($user);
        }
        $this->employee($this->account());
        $this->actingAs($user)->getJson('/api/cards')->assertOk()->assertJsonCount(35);
    }
}
