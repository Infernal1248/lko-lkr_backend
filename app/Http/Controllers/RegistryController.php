<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\RegistryData;
use App\Services\RegistryExport;
use Illuminate\Http\Request;

class RegistryController extends Controller
{
    public function update(Request $r, string $id): mixed
    {
        $r->validate(['data_rights' => 'accepted', 'registry' => 'nullable|array']);
        $g = Generation::where('user_id', $r->user()->id)->whereNull('deleted_at')->findOrFail($id);
        $g->update(['registry' => RegistryData::validate($r->input('registry') ?? []), 'rights_confirmed_at' => now()]);

        return $g;
    }

    public function export(Request $r, string $format, RegistryExport $export): mixed
    {
        abort_unless(in_array($format, ['pdf', 'xlsx']), 404);
        $r->validate(['q' => 'nullable|string|max:160']);
        $q = mb_strtolower(trim($r->input('q', '')));
        $rows = Generation::where('user_id', $r->user()->id)->whereNull('deleted_at')->select(['id', 'template_key', 'snapshot', 'registry', 'card_number', 'created_at'])->orderBy('created_at')->cursor()->filter(function ($g) use ($q) {
            $d = $g->snapshot['data'] ?? [];

            return $q === '' || str_contains(mb_strtolower(implode(' ', [$d['surname'] ?? '', $d['name'] ?? '', $d['patronymic'] ?? '', $g->card_number ?? ''])), $q);
        });
        $rows = $rows->take(2001)->collect();
        abort_if($rows->count() > 2000, 422, 'Выгрузка ограничена 2000 строками. Уточните поиск.');
        $bytes = $export->$format($rows, $r->user()->organization ?? []);
        $type = $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response($bytes, 200, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="registry-'.date('Y-m-d').'.'.$format.'"']);
    }
}
