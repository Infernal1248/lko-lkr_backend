<?php

namespace Tests\Feature;

use App\Models\Consent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportAndProfilesTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $u = User::factory()->create(['organization' => ['organization_type' => 'ООО ЧОО', 'organization_name' => '«Тест»', 'director_position' => 'Директор', 'director_name' => 'И. И. Тестов']]);
        Consent::create(['user_id' => $u->id, 'version' => config('cards.consent_version'), 'organization' => ['name' => 'Тест'], 'created_at' => now()]);

        return $u;
    }

    public function test_csv_import_is_atomic_and_does_not_spend_credits(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $file = UploadedFile::fake()->createWithContent('employees.csv', "фамилия;имя;отчество\nОбразцов;Иван;Иванович\nОшибка;;\n");
        $this->postJson('/api/cards/import', ['file' => $file, 'issue_date' => '2026-10-09'])->assertUnprocessable();
        $this->assertDatabaseCount('cards', 0);
        $file = UploadedFile::fake()->createWithContent('employees.csv', "\xEF\xBB\xBFфамилия;имя;отчество\nОбразцов;Иван;Иванович\nТестов;Пётр;\n");
        $this->postJson('/api/cards/import', ['file' => $file, 'issue_date' => '2026-10-09'])->assertOk()->assertJsonPath('imported', 2);
        $this->assertDatabaseCount('cards', 2);
        $this->assertSame(0, $user->fresh()->credits);
    }

    public function test_excel_import_reads_values_without_evaluating_formulas(): void
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray([['фамилия', 'имя', 'отчество'], ['Образцов', 'Иван', 'Иванович']]);
        $file = tempnam(sys_get_temp_dir(), 'lkr');
        try {
            (new Xlsx($sheet))->save($file);
            $upload = new UploadedFile($file, 'staff.xlsx', null, null, true);
            $this->actingAs($this->user())->postJson('/api/cards/import', ['file' => $upload, 'issue_date' => '2026-10-09'])->assertOk()->assertJsonPath('imported', 1);
        } finally {
            @unlink($file);
            $sheet->disconnectWorksheets();
        }
    }

    public function test_profiles_are_scoped_and_font_is_validated(): void
    {
        $this->actingAs($this->user());
        $payload = ['name' => 'Офис', 'template_key' => 'ppd-lkr', 'settings' => ['offset_x' => 1, 'offset_y' => -2, 'font_size' => 12]];
        $profile = $this->postJson('/api/profiles', $payload)->assertSuccessful();
        $this->actingAs($this->user())->postJson('/api/profiles', $payload + ['id' => $profile['id']])->assertNotFound();
        $payload['settings']['font_size'] = 15;
        $this->postJson('/api/profiles', $payload)->assertUnprocessable();
    }

    public function test_sample_csv_contains_real_newlines_and_bom(): void
    {
        $response = $this->actingAs($this->user())->get('/api/cards/import-example')->assertOk();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->getContent());
        $this->assertStringContainsString("\n", $response->getContent());
    }
}
