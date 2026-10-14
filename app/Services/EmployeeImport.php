<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class EmployeeImport
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }

    private function heading(mixed $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', str_replace("\xEF\xBB\xBF", '', (string) $value))));
    }

    /** Read literal values only: uploaded formulas must never be evaluated. */
    public function read(UploadedFile $file, array $organization): array
    {
        $registry = false;
        $calendar = Date::CALENDAR_WINDOWS_1900;
        $firstRow = 2;
        if (strtolower($file->getClientOriginalExtension()) === 'csv') {
            $text = file_get_contents($file->getRealPath());
            if (! mb_check_encoding($text, 'UTF-8')) {
                $this->fail('Используйте CSV в UTF-8.');
            }
            $lines = preg_split('/\r\n|\n|\r/', $text);
            $delimiter = str_contains($lines[0], ';') ? ';' : ',';
            $rows = array_map(fn ($line) => str_getcsv($line, $delimiter, '"', ''), $lines);
        } else {
            $zip = new \ZipArchive;
            if ($zip->open($file->getRealPath()) !== true) {
                $this->fail('Некорректный XLSX.');
            }
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $size += $zip->statIndex($i)['size'];
            }
            $zip->close();
            if ($size > 20 * 1024 * 1024) {
                $this->fail('Распакованный файл слишком большой.');
            }
            $book = null;
            try {
                $reader = IOFactory::createReader('Xlsx');
                $reader->setReadDataOnly(true);
                $book = $reader->load($file->getRealPath());
                $sheet = $book->getActiveSheet();
                $calendar = $book->getExcelCalendar();
                if ($sheet->getHighestDataRow() > 504) {
                    $this->fail('Не более 500 сотрудников за один импорт.');
                }
                $rows = $sheet->rangeToArray('A1:M'.max(4, $sheet->getHighestDataRow()), null, false, false, false);
                $template = IOFactory::load(resource_path('registry/template.xlsx'));
                try {
                    $headings = $template->getActiveSheet()->rangeToArray('A4:M4', null, false, false)[0];
                    $registry = array_map($this->heading(...), $rows[3]) === array_map($this->heading(...), $headings);
                } finally {
                    $template->disconnectWorksheets();
                }
            } catch (Exception $e) {
                $this->fail('Не удалось прочитать XLSX. Загрузите выгрузку реестра или файл по образцу.');
            } finally {
                $book?->disconnectWorksheets();
            }
        }
        if ($registry) {
            $rows = array_slice($rows, 4);
            $firstRow = 5;
        } else {
            $head = array_map($this->heading(...), array_shift($rows) ?? []);
            if (array_slice($head, 0, 3) !== ['фамилия', 'имя', 'отчество']) {
                $this->fail('Загрузите Excel-реестр, выгруженный из кабинета, или образец со столбцами: фамилия, имя, отчество.');
            }
        }
        $valid = [];
        foreach ($rows as $index => $row) {
            if (! count(array_filter($row, fn ($value) => trim((string) $value) !== ''))) {
                continue;
            }
            if (count($valid) >= 500) {
                $this->fail('Не более 500 сотрудников за один импорт.');
            }
            try {
                foreach ($registry ? [1, 2, 3, 10] : [0, 1, 2] as $column) {
                    if (str_starts_with(trim((string) ($row[$column] ?? '')), '=')) {
                        $this->fail('Замените формулу в данных сотрудника обычным значением.');
                    }
                }
                $names = $registry
                    ? preg_split('/\s+/u', trim((string) ($row[1] ?? '')), 3)
                    : array_slice($row, 0, 3);
                $data = array_merge($organization, [
                    'surname' => trim((string) ($names[0] ?? '')),
                    'name' => trim((string) ($names[1] ?? '')),
                    'patronymic' => trim((string) ($names[2] ?? '')),
                    'issue_date' => '',
                ]);
                $employee = CardInput::employee(['template_key' => 'ppd-lkr', 'data' => $data]);
                $personal = [];
                if ($registry) {
                    $birth = trim((string) ($row[2] ?? ''));
                    if ($birth !== '' && is_numeric($birth)) {
                        $previousCalendar = Date::getExcelCalendar();
                        try {
                            Date::setExcelCalendar($calendar);
                            $birth = Date::excelToDateTimeObject((float) $birth)->format('Y-m-d');
                        } finally {
                            Date::setExcelCalendar($previousCalendar);
                        }
                    } elseif (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $birth, $parts)) {
                        $birth = "$parts[3]-$parts[2]-$parts[1]";
                    }
                    $certificate = trim((string) ($row[10] ?? ''));
                    $parts = explode('№', $certificate, 2);
                    $personal = [
                        'birth_date' => $birth !== '' ? $birth : null,
                        'birth_place' => trim((string) ($row[3] ?? '')),
                        'is_guard' => $certificate !== '',
                        'guard_series' => trim($parts[0]),
                        'guard_number' => trim($parts[1] ?? ''),
                    ];
                }
                $employee['registry'] = RegistryData::validate($personal);
                $valid[] = $employee;
            } catch (ValidationException $e) {
                $messages = $e->validator?->errors()->all() ?? array_merge(...array_values($e->errors()));
                $this->fail('Ошибка в строке '.($index + $firstRow).': '.implode(' ', $messages));
            }
        }
        if ($valid === []) {
            $this->fail('В файле нет сотрудников для импорта.');
        }

        return $valid;
    }
}
