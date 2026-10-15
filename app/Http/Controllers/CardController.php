<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Services\CardInput;
use App\Services\DeletionEvidence;
use App\Services\EmployeeImport;
use App\Services\RegistryData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CardController extends Controller
{
    public function index(Request $request): mixed
    {
        return Card::where('user_id', $request->user()->id)->latest()->get();
    }

    public function store(Request $request): mixed
    {
        $request->validate(['data_rights' => 'accepted', 'registry' => 'nullable|array']);
        $d = CardInput::employee($request->all());
        $d['registry'] = RegistryData::validate($request->input('registry') ?? []);
        $d['rights_confirmed_at'] = now();

        return Card::create($d + ['user_id' => $request->user()->id]);
    }

    public function update(Request $request, int $id): mixed
    {
        $card = Card::where('user_id', $request->user()->id)->findOrFail($id);
        $request->validate(['data_rights' => 'accepted', 'registry' => 'nullable|array']);
        $card->update(CardInput::employee($request->all()) + ['registry' => RegistryData::validate($request->input('registry') ?? []), 'rights_confirmed_at' => now()]);

        return $card;
    }

    public function destroy(Request $request, int $id): mixed
    {
        DeletionEvidence::card($request->user()->id, $id);

        return response()->noContent();
    }

    public function sample(): mixed
    {
        return response("\xEF\xBB\xBFфамилия;имя;отчество\nОбразцов;Иван;Иванович\n", 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="import-example.csv"']);
    }

    public function import(Request $request): mixed
    {
        $request->validate(['data_rights' => 'accepted', 'file' => 'required|file|max:2048|extensions:csv,xlsx']);
        $valid = app(EmployeeImport::class)->read($request->file('file'), $request->user()->organization ?? []);
        DB::transaction(function () use ($valid, $request) {
            foreach ($valid as $d) {
                Card::create($d + ['user_id' => $request->user()->id, 'rights_confirmed_at' => now()]);
            }
        });

        return ['imported' => count($valid)];
    }
}
