<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Services\CardInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CardController extends Controller
{
    public function index(Request $request): mixed
    {
        return Card::where('user_id', $request->user()->id)->latest()->get();
    }

    public function store(Request $request): mixed
    {
        $d = CardInput::validate($request->all());

        return Card::create($d + ['user_id' => $request->user()->id]);
    }

    public function update(Request $request, int $id): mixed
    {
        $card = Card::where('user_id', $request->user()->id)->findOrFail($id);
        $card->update(CardInput::validate($request->all()));

        return $card;
    }

    public function destroy(Request $request, int $id): mixed
    {
        Card::where('user_id', $request->user()->id)->findOrFail($id)->delete();

        return response()->noContent();
    }

    public function sample(): mixed
    {
        return response("\xEF\xBB\xBFфамилия;имя;отчество\nОбразцов;Иван;Иванович\n", 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="import-example.csv"']);
    }

    public function import(Request $request): mixed
    {
        $request->validate(['file' => 'required|file|max:2048|extensions:csv,xlsx', 'issue_date' => 'required|date_format:Y-m-d']);
        $org = $request->user()->organization;
        if (! $org) {
            throw ValidationException::withMessages(['file' => 'Сначала сохраните реквизиты организации.']);
        }
        $file = $request->file('file');
        $rows = [];
        if (strtolower($file->getClientOriginalExtension()) === 'csv') {
            $text = file_get_contents($file->getRealPath());
            abort_unless(mb_check_encoding($text, 'UTF-8'), 422, 'Используйте CSV в UTF-8.');
            $lines = preg_split('/\r\n|\n|\r/', $text);
            $delimiter = str_contains($lines[0], ';') ? ';' : ',';
            $rows = array_map(fn ($line) => str_getcsv($line, $delimiter, '"', ''), $lines);
        } else {
            $zip = new \ZipArchive;
            abort_unless($zip->open($file->getRealPath()) === true, 422, 'Некорректный XLSX.');
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $size += $zip->statIndex($i)['size'];
            } $zip->close();
            abort_if($size > 20 * 1024 * 1024, 422, 'Распакованный файл слишком большой.');
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $book = $reader->load($file->getRealPath());
            $sheet = $book->getActiveSheet();
            abort_if($sheet->getHighestDataRow() > 501, 422, 'Не более 500 сотрудников за один импорт.');
            $rows = $sheet->rangeToArray('A1:C'.$sheet->getHighestDataRow(), null, false, false, false);
            $book->disconnectWorksheets();
        }
        $rows = array_values(array_filter($rows, fn ($row) => count(array_filter($row, fn ($v) => $v !== null && $v !== '')) > 0));
        abort_if(count($rows) > 501, 422, 'Не более 500 сотрудников.');
        $head = array_map(fn ($s) => mb_strtolower(trim(ltrim((string) $s, "\xEF\xBB\xBF"))), array_shift($rows) ?? []);
        abort_unless(array_slice($head, 0, 3) === ['фамилия', 'имя', 'отчество'], 422, 'Первые столбцы: фамилия; имя; отчество.');
        $valid = [];
        foreach ($rows as $i => $row) {
            try {
                $valid[] = CardInput::validate(['template_key' => 'ppd-lkr', 'data' => array_merge($org, ['surname' => trim((string) ($row[0] ?? '')), 'name' => trim((string) ($row[1] ?? '')), 'patronymic' => trim((string) ($row[2] ?? '')), 'issue_date' => $request->input('issue_date')])]);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['file' => 'Ошибка в строке '.($i + 2).': '.implode(' ', $e->validator->errors()->all())]);
            }
        }
        DB::transaction(function () use ($valid, $request) {
            foreach ($valid as $d) {
                Card::create($d + ['user_id' => $request->user()->id]);
            }
        });

        return ['imported' => count($valid)];
    }
}
