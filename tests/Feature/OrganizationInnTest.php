<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Consent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class OrganizationInnTest extends TestCase
{
    use RefreshDatabase;

    public function test_inn_is_saved_exported_and_excluded_from_imported_employee_data(): void
    {
        $user = User::factory()->create();
        Consent::create(['user_id' => $user->id, 'version' => config('cards.consent_version'), 'organization' => ['name' => 'Тест'], 'created_at' => now()]);
        $organization = ['organization_type' => 'ООО ЧОО', 'organization_name' => '«Тест»', 'director_position' => 'Директор', 'director_name' => 'И. И. Тестов', 'inn' => '0123456789'];
        $this->actingAs($user)->putJson('/api/organization', $organization)->assertOk()->assertJsonPath('inn', '0123456789');
        $this->assertSame('0123456789', $user->fresh()->organization['inn']);
        $this->getJson('/api/me')->assertJsonPath('user.organization.inn', '0123456789');
        $file = tempnam(sys_get_temp_dir(), 'inn-export');
        try {
            file_put_contents($file, $this->get('/api/registry/export/xlsx')->assertOk()->getContent());
            $book = IOFactory::load($file);
            $this->assertSame("Реестр учета выдачи ЛКО и ЛКР  ООО ЧОО «Тест»\nИНН 0123456789", $book->getActiveSheet()->getCell('G2')->getValue());
            $this->assertTrue($book->getActiveSheet()->getStyle('G2')->getAlignment()->getWrapText());
            $book->disconnectWorksheets();
        } finally {
            unlink($file);
        }
        $this->get('/api/registry/export/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson('/api/cards/import', ['data_rights' => true, 'file' => UploadedFile::fake()->createWithContent('staff.csv', "фамилия;имя;отчество\nИванов;Иван;Иванович\n")])->assertOk()->assertJsonPath('imported', 1);
        $this->assertArrayNotHasKey('inn', Card::sole()->data);
        foreach (['123', '123456789a'] as $invalid) {
            $this->putJson('/api/organization', array_replace($organization, ['inn' => $invalid]))->assertUnprocessable()->assertJsonValidationErrors('inn');
        }
        $this->putJson('/api/organization', array_replace($organization, ['inn' => '012345678901']))->assertOk();
        $this->putJson('/api/organization', array_replace($organization, ['inn' => '']))->assertOk();
    }
}
