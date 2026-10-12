<?php

namespace App\Services;

use App\Models\Generation;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class RegistryExport
{
    private function headings(): array
    {
        return ['ФИО работника', 'Дата рождения', 'Место рождения', 'Должность', 'Дата назначения', 'Приказ о приёме', 'Серия карточки', 'Номер карточки', 'Дата выдачи', 'Серия удостоверения охранника', 'Номер удостоверения охранника', 'Дата увольнения', 'Приказ об увольнении', 'Заполненность'];
    }

    private function date(?string $s): string
    {
        return $s ? Carbon::parse($s)->format('d.m.Y') : '';
    }

    private function row(Generation $g): array
    {
        $d = $g->snapshot['data'] ?? [];
        $r = $g->registry ?? [];

        return [trim(implode(' ', [$d['surname'] ?? '', $d['name'] ?? '', $d['patronymic'] ?? ''])), $this->date($r['birth_date'] ?? null), $r['birth_place'] ?? '', $r['position'] ?? '', $this->date($r['appointed_at'] ?? null), $r['hire_order'] ?? '', config('cards.templates.'.$g->template_key.'.series'), $g->card_number ?? '', $this->date($d['issue_date'] ?? null), ($r['is_guard'] ?? false) ? ($r['guard_series'] ?? '') : 'Не применяется', ($r['is_guard'] ?? false) ? ($r['guard_number'] ?? '') : 'Не применяется', $this->date($r['dismissed_at'] ?? null), $r['dismissal_order'] ?? '', $g->registry_complete ? 'Сведения заполнены' : 'Сведения неполные'];
    }

    public function xlsx(Collection $items): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Реестр карточек');
        $rows = [$this->headings()];
        foreach ($items as $g) {
            $rows[] = $this->row($g);
        }
        foreach ($rows as $ri => $row) {
            foreach ($row as $ci => $value) {
                $sheet->setCellValueExplicit([$ci + 1, $ri + 1], (string) $value, DataType::TYPE_STRING);
            }
        }
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:N'.count($rows));
        $sheet->getStyle('A1:N1')->getFont()->setBold(true);
        $sheet->getStyle('A1:N'.count($rows))->getAlignment()->setWrapText(true)->setVertical('top');
        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setWidth(in_array($col, ['A', 'C', 'F', 'M']) ? 32 : 20);
        }
        ob_start();
        try {
            (new Xlsx($book))->save('php://output');

            return ob_get_contents();
        } finally {
            ob_end_clean();
            $book->disconnectWorksheets();
        }
    }

    public function pdf(Collection $items): string
    {
        $pdf = app(PdfRenderer::class)->registryDocument();
        $pdf->SetTitle('Реестр личных карточек');
        $pdf->SetAutoPageBreak(false, 12);
        $pdf->SetDrawColor(130, 145, 155);
        $pdf->SetLineWidth(0.15);
        $pdf->setCellPaddings(1.5, 1.5, 1.5, 1.5);
        $widths = [42, 22, 42, 30, 22, 37, 16, 27, 22, 21, 25, 22, 37, 35];
        $page = 0;
        $header = function () use ($pdf, $widths, &$page): void {
            if ($page > 0) {
                $pdf->AddPage();
            }
            $page++;
            $pdf->SetXY(10, 10);
            $pdf->SetFont('liberationserif', 'B', 14, resource_path('fonts/liberationserifb.json'));
            $pdf->Cell(330, 9, 'Реестр личных карточек — '.date('d.m.Y'), 0, 0);
            $pdf->SetFont('liberationserif', 'B', 9, resource_path('fonts/liberationserifb.json'));
            $pdf->Cell(70, 9, 'Страница '.$page, 0, 1, 'R');
            $pdf->SetFillColor(233, 239, 243);
            $x = 10.0;
            foreach ($this->headings() as $i => $heading) {
                $pdf->MultiCell($widths[$i], 20, $heading, 1, 'L', true, 0, $x, 22, true, 0, false, true, 20, 'M');
                $x += $widths[$i];
            }
            $pdf->SetXY(10, 42);
        };
        $header();
        if ($items->isEmpty()) {
            $pdf->Cell(400, 12, 'Нет записей', 1, 1);
        }
        foreach ($items as $g) {
            $row = array_map(fn ($value) => (string) ($value === '' ? '—' : $value), $this->row($g));
            $height = 10.0;
            foreach ($row as $i => $text) {
                $height = max($height, $pdf->getStringHeight($widths[$i], $text) + 2);
            }
            if ($pdf->GetY() + $height > 285) {
                $header();
            }
            $x = 10.0;
            $y = $pdf->GetY();
            foreach ($row as $i => $text) {
                $pdf->MultiCell($widths[$i], $height, $text, 1, 'L', false, 0, $x, $y, true, 0, false, true, $height, 'T');
                $x += $widths[$i];
            }
            $pdf->SetXY(10, $y + $height);
        }

        return $pdf->Output('registry.pdf', 'S');
    }
}
