<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Consent;
use App\Models\Generation;
use App\Models\User;
use App\Services\CardInput;
use App\Services\RegistryExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class RegistryEmployeeImportTest extends TestCase
{
    use RefreshDatabase;

    private function account(): User
    {
        $user = User::factory()->create(['credits' => 25, 'organization' => null]);
        Consent::create(['user_id' => $user->id, 'version' => config('cards.consent_version'), 'organization' => ['name' => 'Новая'], 'created_at' => now()]);
        $this->actingAs($user);

        return $user;
    }

    private function upload(Spreadsheet $book): UploadedFile
    {
        ob_start();
        try {
            (new Xlsx($book))->save('php://output');
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
            $book->disconnectWorksheets();
        }

        return UploadedFile::fake()->createWithContent('registry.xlsx', $bytes);
    }

    public function test_actual_export_imports_only_personal_fields_into_current_account(): void
    {
        $source = User::factory()->create();
        Card::create(['user_id' => $source->id, 'template_key' => 'ppd-lko', 'data' => CardInput::demo()]);
        $generation = new Generation([
            'template_key' => 'ppd-lko', 'card_number' => '000999',
            'snapshot' => ['data' => CardInput::demo()],
            'registry' => [
                'birth_date' => '1990-01-02', 'birth_place' => 'Красноярск',
                'is_guard' => true, 'guard_series' => '0001', 'guard_number' => '000023',
                'position' => 'Старая должность', 'appointed_at' => '2020-01-01',
                'hire_order' => 'Старый приказ', 'dismissed_at' => '2026-01-01',
                'dismissal_order' => 'Приказ об увольнении',
            ],
        ]);
        $bytes = app(RegistryExport::class)->xlsx(collect([$generation]), ['organization_name' => 'Прежняя организация']);
        $user = $this->account();
        $this->postJson('/api/cards/import', [
            'file' => UploadedFile::fake()->createWithContent('registry.xlsx', $bytes),
            'data_rights' => true, 'issue_date' => '2020-01-01',
        ])->assertOk()->assertJsonPath('imported', 1);
        $card = Card::where('user_id', $user->id)->sole();
        $this->assertSame('Образцов', $card->data['surname']);
        $this->assertSame('Иван', $card->data['name']);
        $this->assertSame('Иванович', $card->data['patronymic']);
        foreach (['organization_name', 'director_name', 'issue_date'] as $key) {
            $this->assertSame('', $card->data[$key]);
        }
        $this->assertSame([
            'birth_date' => '1990-01-02', 'birth_place' => 'Красноярск',
            'is_guard' => true, 'guard_series' => '0001', 'guard_number' => '000023',
        ], $card->registry);
        $this->assertNotNull($card->rights_confirmed_at);
        $this->assertSame(25, $user->fresh()->credits);
        $this->assertSame(1, Card::where('user_id', $source->id)->count());
        $this->assertDatabaseCount('generations', 0);
    }

    public function test_empty_optional_fields_and_text_dates_are_supported(): void
    {
        $this->account();
        $book = IOFactory::load(resource_path('registry/template.xlsx'));
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('B5', 'Петрова Анна');
        $sheet->setCellValue('C5', '02.01.1990');
        $sheet->setCellValue('B7', 'Иванов Пётр Иванович');
        $sheet->setCellValue('K7', '№ 000123');
        $this->postJson('/api/cards/import', ['file' => $this->upload($book), 'data_rights' => true])
            ->assertOk()->assertJsonPath('imported', 2);
        $cards = Card::orderBy('id')->get();
        $this->assertSame('', $cards[0]->data['patronymic']);
        $this->assertSame('1990-01-02', $cards[0]->registry['birth_date']);
        $this->assertFalse($cards[0]->registry['is_guard']);
        $this->assertNull($cards[1]->registry['birth_date']);
        $this->assertSame('000123', $cards[1]->registry['guard_number']);
    }

    public function test_bad_rows_reject_entire_import_with_original_excel_row_number(): void
    {
        $this->account();
        foreach (['31.02.1990', '=TODAY()'] as $invalid) {
            $book = IOFactory::load(resource_path('registry/template.xlsx'));
            $book->getActiveSheet()->setCellValue('B5', 'Иванов Иван');
            $book->getActiveSheet()->setCellValue('B7', 'Петров Пётр');
            $book->getActiveSheet()->setCellValue('C7', $invalid);
            $response = $this->postJson('/api/cards/import', ['file' => $this->upload($book), 'data_rights' => true]);
            $response->assertUnprocessable()->assertJsonValidationErrors('file');
            $this->assertStringContainsString('строке 7', $response->json('errors.file.0'));
            $this->assertDatabaseCount('cards', 0);
        }
    }

    public function test_registry_requires_consent_and_rejects_wrong_headers_and_empty_files(): void
    {
        $this->account();
        $book = IOFactory::load(resource_path('registry/template.xlsx'));
        $book->getActiveSheet()->setCellValue('B5', 'Иванов Иван');
        $this->postJson('/api/cards/import', ['file' => $this->upload($book)])->assertUnprocessable()->assertJsonValidationErrors('data_rights');
        foreach ([false, true] as $wrongHeader) {
            $book = IOFactory::load(resource_path('registry/template.xlsx'));
            if ($wrongHeader) {
                $book->getActiveSheet()->setCellValue('B4', 'Неверный столбец');
                $book->getActiveSheet()->setCellValue('B5', 'Иванов Иван');
            }
            $this->postJson('/api/cards/import', ['file' => $this->upload($book), 'data_rights' => true])->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $this->assertDatabaseCount('cards', 0);
    }

    public function test_registry_allows_500_employees_but_rejects_501_atomically(): void
    {
        $user = $this->account();
        foreach ([501, 500] as $count) {
            $book = IOFactory::load(resource_path('registry/template.xlsx'));
            for ($i = 5; $i < $count + 5; $i++) {
                $book->getActiveSheet()->setCellValue('B'.$i, 'Иванов Иван');
            }
            $response = $this->postJson('/api/cards/import', ['file' => $this->upload($book), 'data_rights' => true]);
            if ($count === 501) {
                $response->assertUnprocessable();
                $this->assertDatabaseCount('cards', 0);
            } else {
                $response->assertOk()->assertJsonPath('imported', 500);
                $this->assertDatabaseCount('cards', 500);
            }
        }
        $this->assertSame(25, $user->fresh()->credits);
        $this->assertDatabaseCount('generations', 0);
    }
}
