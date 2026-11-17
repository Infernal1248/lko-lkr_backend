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

    public function test_pdf_and_excel_include_all_releases_of_selected_employees_only(): void
    {
        $user = $this->account();
        $a = $this->employee($user);
        $b = $this->employee($user);
        $notSelected = $this->employee($user);
        $other = $this->account();
        $foreign = $this->employee($other);
        for ($i = 0; $i < 31; $i++) {
            $this->generation($user, $a);
        }
        $this->generation($user, $b, ['inactive_at' => now()]);
        $this->generation($user, $a, ['deleted_at' => now()]);
        $this->generation($user, $notSelected);
        $this->generation($other, $foreign);
        $expectedIds = Generation::where('user_id', $user->id)->whereIn('employee_id', [$a->id, $b->id])->whereNull('deleted_at')->pluck('id')->all();
        $export = $this->mock(RegistryExport::class);
        foreach (['pdf', 'xlsx'] as $format) {
            $export->shouldReceive($format)->once()->withArgs(function ($items, $organization) use ($expectedIds) {
                $this->assertCount(32, $items);
                foreach ($items as $item) {
                    $this->assertContains($item->id, $expectedIds);
                }

                return true;
            })->andReturn('export');
            $this->actingAs($user)->postJson('/api/registry/export/'.$format, ['employee_ids' => [$a->id, $b->id]])->assertOk()->assertContent('export');
        }
        $this->assertSame(0, $user->fresh()->credits);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_selection_cannot_include_foreign_missing_duplicate_or_empty_employee_ids(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = $this->account();
        $mine = $this->employee($user);
        $other = $this->account();
        $foreign = $this->employee($other);
        $this->actingAs($user);
        foreach ([[], [$foreign->id], [$mine->id, $foreign->id], [999999], [$mine->id, $mine->id]] as $ids) {
            $this->postJson('/api/registry/export/pdf', ['employee_ids' => $ids])->assertUnprocessable();
        }
        $this->postJson('/api/registry/export/xlsx')->assertUnprocessable();
        $this->getJson('/api/registry/export/pdf?employee_ids[]=999999')->assertUnprocessable();
    }
}
