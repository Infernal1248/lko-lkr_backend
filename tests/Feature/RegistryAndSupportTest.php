<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Consent;
use App\Models\Generation;
use App\Models\Payment;
use App\Models\PrinterProfile;
use App\Models\SupportMessage;
use App\Models\User;
use App\Services\CardInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class RegistryAndSupportTest extends TestCase
{
    use RefreshDatabase;

    private function account(): User
    {
        $u = User::factory()->create(['credits' => 5]);
        Consent::create(['user_id' => $u->id, 'version' => config('cards.consent_version'), 'organization' => ['name' => 'Тест'], 'created_at' => now()]);
        $this->actingAs($u);

        return $u;
    }

    private function payload(User $u): array
    {
        $p = PrinterProfile::create(['user_id' => $u->id, 'name' => 'Canon у окна', 'template_key' => 'ppd-lkr', 'settings' => ['offset_x' => 1, 'offset_y' => 0, 'font_size' => 12, 'fields' => []], 'calibrated_at' => now()]);

        return ['template_key' => 'ppd-lkr', 'data' => CardInput::demo(), 'settings' => ['offset_x' => 0, 'offset_y' => 0, 'font_size' => 12, 'fields' => []], 'request_key' => (string) Str::uuid(), 'profile_id' => $p->id, 'data_rights' => true];
    }

    private function registry(): array
    {
        return ['birth_date' => '1990-01-02', 'birth_place' => 'Красноярск', 'position' => 'Охранник', 'appointed_at' => '2026-01-01', 'hire_order' => '№ 12-к от 01.01.2026', 'is_guard' => true, 'guard_series' => '0001', 'guard_number' => '000023'];
    }

    public function test_generation_creates_employee_atomically_and_reuses_explicit_employee(): void
    {
        $u = $this->account();
        $d = $this->payload($u);
        $g = $this->postJson('/api/generations', $d)->assertCreated()->assertJsonPath('registry_complete', false)->json();
        $this->assertDatabaseCount('cards', 1);
        $this->assertSame(1, $g['snapshot']['settings']['offset_x']);
        $this->postJson('/api/generations', $d)->assertCreated();
        $this->assertDatabaseCount('cards', 1);
        $d['request_key'] = (string) Str::uuid();
        $d['employee_id'] = $g['employee_id'];
        $d['data']['name'] = 'Пётр';
        $this->postJson('/api/generations', $d)->assertCreated();
        $this->assertDatabaseCount('cards', 1);
        $this->assertDatabaseCount('generations', 2);
        $this->assertSame(3, $u->fresh()->credits);
    }

    public function test_missing_authority_and_unconfigured_foreign_or_wrong_series_printer_are_rejected(): void
    {
        $u = $this->account();
        $d = $this->payload($u);
        $d['data_rights'] = false;
        $this->postJson('/api/generations', $d)->assertUnprocessable()->assertJsonValidationErrors('data_rights');
        $this->postJson('/api/cards', $d)->assertUnprocessable();
        $d['data_rights'] = true;
        $p = PrinterProfile::find($d['profile_id']);
        $p->update(['calibrated_at' => null]);
        $this->postJson('/api/generations', $d)->assertUnprocessable()->assertJsonValidationErrors('profile_id');
        $p->update(['calibrated_at' => now(), 'template_key' => 'ppd-lko']);
        $this->postJson('/api/generations', $d)->assertUnprocessable();
        $p->update(['template_key' => 'ppd-lkr', 'user_id' => User::factory()->create()->id]);
        $this->postJson('/api/generations', $d)->assertUnprocessable();
        $this->assertDatabaseCount('cards', 0);
        $this->assertSame(5, $u->fresh()->credits);
    }

    public function test_foreign_employee_cannot_be_modified_or_charged(): void
    {
        $u = $this->account();
        $d = $this->payload($u);
        $other = Card::create(['user_id' => User::factory()->create()->id, 'template_key' => 'ppd-lkr', 'data' => CardInput::demo()]);
        $d['employee_id'] = $other->id;
        $this->postJson('/api/generations', $d)->assertNotFound();
        $this->assertSame(5, $u->fresh()->credits);
        $this->assertDatabaseCount('generations', 0);
    }

    public function test_registry_is_optional_can_be_completed_later_and_never_changes_pdf_or_balance(): void
    {
        $u = $this->account();
        $g = $this->postJson('/api/generations', $this->payload($u))->assertCreated()->json();
        $pdf = Generation::find($g['id'])->pdf;
        $this->putJson('/api/generations/'.$g['id'].'/registry', ['registry' => ['birth_place' => 'Красноярск'], 'data_rights' => true])->assertOk()->assertJsonPath('registry_complete', false);
        $this->putJson('/api/generations/'.$g['id'].'/registry', ['registry' => $this->registry(), 'data_rights' => true])->assertOk()->assertJsonPath('registry_complete', false);
        $this->putJson('/api/generations/'.$g['id'].'/number', ['card_number' => '000001'])->assertOk()->assertJsonPath('registry_complete', true);
        $this->assertSame($pdf, Generation::find($g['id'])->pdf);
        $this->assertSame(4, $u->fresh()->credits);
        $this->assertStringNotContainsString('Красноярск', DB::table('generations')->value('registry'));
        $this->putJson('/api/generations/'.$g['id'].'/registry', ['registry' => [], 'data_rights' => false])->assertUnprocessable();
        $this->account();
        $this->putJson('/api/generations/'.$g['id'].'/registry', ['registry' => [], 'data_rights' => true])->assertNotFound();
    }

    public function test_export_matches_supplied_columns_and_leaves_optional_cells_blank(): void
    {
        $u = $this->account();
        $u->update(['organization' => ['organization_type' => 'ООО ЧОО', 'organization_name' => '«Образец»']]);
        $d = $this->payload($u);
        $this->postJson('/api/generations', $d)->assertCreated();
        $bytes = $this->get('/api/registry/export/xlsx')->assertOk()->getContent();
        $file = tempnam(sys_get_temp_dir(), 'registry-template');
        try {
            file_put_contents($file, $bytes);
            $book = IOFactory::load($file);
            $sheet = $book->getActiveSheet();
            $this->assertSame(['№ п/п', 'ФИО', 'Дата рождения', 'Место рождения', 'Наименование должности', 'Дата назначения на должность', 'Реквизиты приказа о назначение на должность', 'Серия карточки', 'Номер карточки', 'Дата выдачи карточки', 'Серия и номер удостоверения частного охранника ', 'Дата увольнения', 'Реквизиты приказа об увольнении'], $sheet->rangeToArray('A4:M4')[0]);
            $this->assertSame('Реестр учета выдачи ЛКО и ЛКР  ООО ЧОО «Образец»', $sheet->getCell('G2')->getValue());
            foreach (['C5', 'K5', 'L5', 'M5', 'B6'] as $cell) {
                $this->assertEmpty($sheet->getCell($cell)->getValue());
            }
            $this->assertSame('thin', $sheet->getStyle('M5')->getBorders()->getRight()->getBorderStyle());
            $this->assertSame('Times New Roman', $sheet->getStyle('B4')->getFont()->getName());
            $book->disconnectWorksheets();
        } finally {
            unlink($file);
        }
    }

    public function test_export_contains_incomplete_rows_and_preserves_numbers_as_text_and_filters_owners(): void
    {
        $u = $this->account();
        $d = $this->payload($u);
        $d['registry'] = $this->registry();
        $d['registry']['position'] = '=1+1';
        $g = $this->postJson('/api/generations', $d)->assertCreated()->json();
        $this->putJson('/api/generations/'.$g['id'].'/number', ['card_number' => '000001'])->assertOk();
        $other = $this->account();
        $this->postJson('/api/generations', $this->payload($other))->assertCreated();
        $this->actingAs($u);
        $response = $this->get('/api/registry/export/xlsx')->assertOk();
        $file = tempnam(sys_get_temp_dir(), 'registry');
        try {
            file_put_contents($file, $response->getContent());
            $book = IOFactory::load($file);
            $sheet = $book->getActiveSheet();
            $this->assertSame(60, $sheet->getHighestRow());
            $this->assertSame('M', $sheet->getHighestColumn());
            $this->assertSame('Реквизиты приказа об увольнении', $sheet->getCell('M4')->getValue());
            $this->assertSame('№ п/п', $sheet->getCell('A4')->getValue());
            $this->assertSame(1, $sheet->getCell('A5')->getValue());
            $this->assertSame('0001 № 000023', $sheet->getCell('K5')->getValue());
            $this->assertSame('n', $sheet->getCell('C5')->getDataType());
            $this->assertSame('02.01.1990', $sheet->getCell('C5')->getFormattedValue());
            $this->assertSame('', $sheet->getAutoFilter()->getRange());
            $this->assertSame('000001', $sheet->getCell('I5')->getValue());
            $this->assertSame('s', $sheet->getCell('E5')->getDataType());
            $this->assertSame('=1+1', $sheet->getCell('E5')->getValue());
            $book->disconnectWorksheets();
        } finally {
            unlink($file);
        }
        $pdf = $this->get('/api/registry/export/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->get('/api/registry/export/xlsx?q=Неттакого')->assertOk();
        $this->assertSame(4, $u->fresh()->credits);
    }

    public function test_support_is_private_and_only_operator_can_send_operator_answers(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $t = $this->postJson('/api/support', ['subject' => 'Помощь с принтером', 'body' => 'Сдвигаются строки'])->assertCreated()->json();
        $other = User::factory()->create();
        $this->actingAs($other);
        $this->getJson('/api/support')->assertJsonPath('total', 0);
        $this->getJson('/api/support/'.$t['id'])->assertNotFound();
        $this->postJson('/api/support/'.$t['id'].'/messages', ['body' => 'Чужой ответ'])->assertNotFound();
        $admin = User::factory()->create(['is_support_admin' => true]);
        $this->actingAs($admin);
        $this->getJson('/api/support')->assertJsonPath('total', 1);
        $this->postJson('/api/support/'.$t['id'].'/messages', ['body' => 'Проверьте масштаб 100%'])->assertCreated()->assertJsonPath('from_support', true);
        $this->actingAs($owner);
        $this->getJson('/api/support/'.$t['id'])->assertOk()->assertJsonPath('ticket.status', 'answered')->assertJsonCount(2, 'messages.data');
        $this->postJson('/api/support/'.$t['id'].'/messages', ['body' => 'Спасибо', 'from_support' => true])->assertCreated()->assertJsonPath('from_support', false);
        $this->assertStringNotContainsString('Спасибо', SupportMessage::latest('id')->first()->getRawOriginal('body'));
        $this->postJson('/api/support/'.$t['id'].'/close')->assertOk()->assertJsonPath('status', 'closed');
    }

    public function test_registration_requires_privacy_and_does_not_allow_admin_escalation(): void
    {
        $d = ['name' => 'Тест', 'email' => 'privacy@example.test', 'password' => 'Password123456', 'password_confirmation' => 'Password123456', 'terms' => true, 'is_support_admin' => true];
        $this->postJson('/api/register', $d)->assertUnprocessable()->assertJsonValidationErrors('privacy');
        $d['privacy'] = true;
        $this->postJson('/api/register', $d)->assertCreated();
        $u = User::first();
        $this->assertFalse($u->is_support_admin);
        $this->assertNotNull($u->privacy_accepted_at);
        $this->assertSame(config('cards.privacy_version'), $u->privacy_version);
    }

    public function test_full_a5_preview_uses_the_whole_template(): void
    {
        $this->actingAs(User::factory()->create());
        $r = $this->get('/api/calibration/background?template_key=ppd-lkr&full=1')->assertOk();
        $this->assertSame(realpath(resource_path('templates/ppd-lkr.png')), $r->baseResponse->getFile()->getRealPath());
    }

    public function test_npd_payment_has_no_54fz_receipt_and_receipt_queue_is_operator_only(): void
    {
        config(['cards.payments_enabled' => true, 'cards.yookassa.shop_id' => 'test', 'cards.yookassa.secret' => 'test', 'cards.yookassa.test' => true, 'cards.receipt_mode' => 'npd_manual']);
        $u = $this->account();
        Http::fake(['api.yookassa.ru/*' => Http::response(['id' => 'provider', 'test' => true, 'confirmation' => ['confirmation_url' => 'https://yookassa.ru/test']])]);
        $d = ['package' => 'start', 'request_key' => (string) Str::uuid(), 'buyer_type' => 'company', 'buyer_name' => 'ООО Тест', 'buyer_inn' => '1234567890'];
        $this->postJson('/api/payments', $d)->assertCreated()->assertJsonPath('test_mode', true);
        Http::assertSent(fn ($r) => ! isset($r['receipt']) && $r['capture'] === true);
        $this->getJson('/api/receipts')->assertForbidden();
        $admin = User::factory()->create(['is_support_admin' => true]);
        $this->actingAs($admin)->getJson('/api/receipts')->assertOk()->assertJsonPath('total', 0);
        $p = Payment::first();
        $p->update(['test_mode' => false, 'status' => 'succeeded', 'paid_at' => now()]);
        $this->getJson('/api/receipts')->assertJsonPath('total', 1);
        $this->putJson('/api/receipts/'.$p->id, ['receipt_url' => 'javascript:alert(1)'])->assertUnprocessable();
        $url = 'https://lknpd.nalog.ru/api/v1/receipt/242022368804/test/print';
        $this->putJson('/api/receipts/'.$p->id, ['receipt_url' => $url])->assertOk();
        $this->actingAs($u)->getJson('/api/payments')->assertJsonPath('data.0.receipt_url', $url);
    }

    public function test_employee_can_be_saved_without_print_data_and_card_generation_remains_strict(): void
    {
        $user = $this->account();
        $input = ['template_key' => 'ppd-lkr', 'data' => ['surname' => 'Тестов', 'name' => 'Иван'], 'data_rights' => true];
        $employee = $this->postJson('/api/cards', $input)->assertCreated()->assertJsonPath('data.issue_date', '')->assertJsonPath('data.organization_name', '');
        $this->assertDatabaseCount('generations', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(5, $user->fresh()->credits);
        $input['data']['name'] = 'Пётр';
        $this->putJson('/api/cards/'.$employee['id'], $input)->assertOk()->assertJsonPath('data.name', 'Пётр');
        $generation = $this->payload($user);
        $generation['employee_id'] = $employee['id'];
        $generation['data'] = $input['data'];
        $this->postJson('/api/generations', $generation)->assertUnprocessable()->assertJsonValidationErrors(['data.organization_name', 'data.issue_date']);
        unset($input['data']['surname']);
        $this->postJson('/api/cards', $input)->assertUnprocessable()->assertJsonValidationErrors('data.surname');
        $this->assertDatabaseCount('cards', 1);
        $this->assertSame(5, $user->fresh()->credits);
    }
}
