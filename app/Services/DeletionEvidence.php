<?php

namespace App\Services;

use App\Models\Card;
use App\Models\Generation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeletionEvidence
{
    public static function card(int $userId, int $id): void
    {
        DB::transaction(function () use ($userId, $id) {
            $card = Card::where('user_id', $userId)->lockForUpdate()->findOrFail($id);
            self::record($userId, 'card', (string) $id, $card->data, $card->registry ?? []);
            $card->delete();
        });
    }

    public static function generation(int $userId, string $id): void
    {
        DB::transaction(function () use ($userId, $id) {
            $generation = Generation::where('user_id', $userId)->lockForUpdate()->findOrFail($id);
            if ($generation->deleted_at !== null) {
                return;
            }
            self::record($userId, 'generation', $id, $generation->snapshot['data'] ?? [], $generation->registry ?? [], filled($generation->pdf), filled($generation->card_number));
            $generation->update(['pdf' => null, 'snapshot' => null, 'registry' => null, 'employee_id' => null, 'card_number' => null, 'card_number_hash' => null, 'assigned_at' => null, 'deleted_at' => now()]);
        });
    }

    private static function record(int $actor, string $type, string $id, array $data, array $registry, bool $pdf = false, bool $number = false): void
    {
        $categories = [];
        $fields = array_merge($data, $registry);
        foreach ([
            'ФИО работника' => ['surname', 'name', 'patronymic'],
            'Сведения об организации' => ['organization_type', 'organization_name'],
            'ФИО и должность руководителя' => ['director_name', 'director_position'],
            'Дата выдачи карточки' => ['issue_date'],
            'Дата и место рождения' => ['birth_date', 'birth_place'],
            'Должность работника' => ['position'],
            'Назначение, увольнение и приказы' => ['appointed_at', 'hire_order', 'dismissed_at', 'dismissal_order'],
            'Удостоверение охранника' => ['guard_series', 'guard_number'],
        ] as $category => $keys) {
            foreach ($keys as $key) {
                if (filled($fields[$key] ?? null)) {
                    $categories[] = $category;
                    break;
                }
            }
        }
        if (array_key_exists('is_guard', $registry)) {
            $categories[] = 'Признак наличия удостоверения охранника';
        }
        if ($pdf) {
            $categories[] = 'PDF карточки';
        }
        if ($number) {
            $categories[] = 'Номер карточки';
        }
        $time = now();
        DB::table('deletion_events')->insert([
            'id' => (string) Str::uuid(), 'actor_id' => $actor, 'resource_type' => $type, 'resource_id' => $id,
            'occurred_at' => $time, 'retain_until' => $time->copy()->addYears(3),
            'evidence' => Crypt::encryptString(json_encode([
                'system' => 'ЛКО / ЛКР',
                'subjects' => array_values(array_filter([
                    trim(implode(' ', array_filter([$data['surname'] ?? '', $data['name'] ?? '', $data['patronymic'] ?? '']))),
                    $data['director_name'] ?? null,
                ])),
                'categories' => $categories,
                'reason' => 'Команда удаления пользователя аккаунта',
                'scope' => $type === 'card' ? 'Одна запись картотеки в рабочей базе данных' : 'Персональные поля и PDF одной записи реестра в рабочей базе данных',
                'method' => $type === 'card' ? 'Удаление строки' : 'Очистка персональных полей и PDF',
                'all_copies_destroyed' => false,
                'note' => 'Другие записи, резервные копии и выгрузки не проверены этой операцией. Запись не заменяет акт уничтожения.',
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        ]);
    }
}
