<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExportDeletionEvidence extends Command
{
    protected $signature = 'privacy:deletions {--id= : UUID записи для выгрузки; без UUID — последние 100 идентификаторов}';

    protected $description = 'Закрытый журнал удаления: просмотр идентификаторов и выгрузка одной записи для подготовки акта';

    public function handle(): int
    {
        $id = $this->option('id');
        if (! $id) {
            $this->table(['UUID', 'Дата ('.config('app.timezone').')', 'Тип', 'ID ресурса'], DB::table('deletion_events')->orderByDesc('occurred_at')->orderBy('id')->limit(100)->get(['id', 'occurred_at', 'resource_type', 'resource_id'])->map(fn ($row) => (array) $row)->all());

            return self::SUCCESS;
        }
        if (! Str::isUuid($id) || ! ($event = DB::table('deletion_events')->where('id', $id)->first())) {
            $this->error('Запись не найдена.');

            return self::FAILURE;
        }
        $this->line(json_encode([
            'event_id' => $event->id, 'actor_id' => $event->actor_id,
            'resource_type' => $event->resource_type, 'resource_id' => $event->resource_id,
            'occurred_at' => Carbon::parse($event->occurred_at, config('app.timezone'))->toIso8601String(),
            'retain_until' => Carbon::parse($event->retain_until, config('app.timezone'))->toIso8601String(),
            'evidence' => json_decode(Crypt::decryptString($event->evidence), true, 512, JSON_THROW_ON_ERROR),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
