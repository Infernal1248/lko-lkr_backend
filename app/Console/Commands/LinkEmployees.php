<?php

namespace App\Console\Commands;

use App\Models\Card;
use App\Models\Generation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LinkEmployees extends Command
{
    protected $signature = 'cards:link-employees';

    protected $description = 'Link legacy generated documents to employees, preserving PDFs';

    public function handle(): int
    {
        $count = 0;
        Generation::whereNull('employee_id')->whereNull('deleted_at')->chunkById(100, function ($items) use (&$count) {
            foreach ($items as $g) {
                DB::transaction(function () use ($g, &$count) {
                    $g = Generation::whereKey($g->id)->lockForUpdate()->first();
                    if ($g->employee_id || ! $g->snapshot) {
                        return;
                    }
                    $data = $g->snapshot['data'];
                    $matches = Card::where('user_id', $g->user_id)->get()->filter(fn ($c) => $c->data == $data);
                    $c = $matches->count() === 1 ? $matches->first() : Card::create(['user_id' => $g->user_id, 'template_key' => $g->template_key, 'data' => $data]);
                    $g->update(['employee_id' => $c->id]);
                    $count++;
                });
            }
        });
        $this->info("Связано документов: $count. Недостающие сведения реестра заполните в кабинете.");

        return self::SUCCESS;
    }
}
