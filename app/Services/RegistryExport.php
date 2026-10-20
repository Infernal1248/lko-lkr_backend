<?php

namespace App\Services;

use App\Models\Generation;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class RegistryExport
{
    private function template(): Spreadsheet
    {
        return IOFactory::load(resource_path('registry/template.xlsx'));
    }

    private function title(array $organization): string
    {
        $organizationName = trim($organization['organization_name'] ?? '');
        if ($organizationName !== '' && ! preg_match('/^(?:«.*»|".*"|“.*”)$/us', $organizationName)) {
            $organizationName = '«'.$organizationName.'»';
        }
        $name = trim(($organization['organization_type'] ?? '').' '.$organizationName);
        $name = preg_replace('/\s+/u', ' ', $name);

        $inn = trim($organization['inn'] ?? '');

        return 'Реестр учета выдачи ЛКО и ЛКР  '.($name !== '' ? $name : 'ООО ЧОО "                     "').($inn !== '' ? ', ИНН '.$inn : '');
    }

    private function date(?string $value): string
    {
        return $value ? Carbon::parse($value)->format('d.m.Y') : '';
    }

    private function row(Generation $g, int $number): array
    {
        $d = $g->snapshot['data'] ?? [];
        $r = $g->registry ?? [];
        $certificate = '';
        if ($r['is_guard'] ?? false) {
            $series = trim($r['guard_series'] ?? '');
            $id = trim($r['guard_number'] ?? '');
            $certificate = trim($series.($id !== '' ? ' № '.$id : ''));
        }

        return [$number, trim(implode(' ', [$d['surname'] ?? '', $d['name'] ?? '', $d['patronymic'] ?? ''])), $this->date($r['birth_date'] ?? null), $r['birth_place'] ?? '', $r['position'] ?? '', $this->date($r['appointed_at'] ?? null), $r['hire_order'] ?? '', config('cards.templates.'.$g->template_key.'.series'), $g->card_number ?? '', $this->date($d['issue_date'] ?? null), $certificate, $this->date($r['dismissed_at'] ?? null), $r['dismissal_order'] ?? ''];
    }

    public function xlsx(Collection $items, array $organization = []): string
    {
        $book = $this->template();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValueExplicit('G2', $this->title($organization), DataType::TYPE_STRING);
        $last = max(60, $items->count() + 4);
        $sheet->duplicateStyle($sheet->getStyle('A5:M5'), 'A5:M'.$last);
        // Row numbers are centred; identifiers remain literal text, including leading zeroes.
        $sheet->getStyle('I5:I'.$last)->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle('A5:A'.$last)->getAlignment()->setHorizontal('center');
        $sheet->getStyle('B5:M'.$last)->getAlignment()->setHorizontal('left')->setVertical('top')->setWrapText(true);
        $sheet->getStyle('A4:M'.$last)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF000000');
        $metrics = app(PdfRenderer::class)->registryDocument();
        $sheet->mergeCells('G2:M2');
        $sheet->getStyle('G2')->getAlignment()->setWrapText(false)->setShrinkToFit(true);
        $metrics->SetFont('liberationsans', '', 11, resource_path('fonts/liberationsans.json'));
        foreach ($items->values() as $index => $g) {
            $row = $this->row($g, $index + 1);
            $height = 15;
            foreach ($row as $ci => $value) {
                $column = chr(65 + $ci);
                $cell = $column.($index + 5);
                if ($ci === 0) {
                    $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_NUMERIC);
                } elseif (in_array($ci, [2, 5, 9, 11], true) && $value !== '') {
                    $sheet->setCellValueExplicit($cell, Date::PHPToExcel(Carbon::createFromFormat('!d.m.Y', $value)), DataType::TYPE_NUMERIC);
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('dd.mm.yyyy');
                } else {
                    $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
                }
                $width = ($sheet->getColumnDimension($column)->getWidth() * 7 + 5) * 25.4 / 96;
                $height = max($height, 15 * count($this->lines($metrics, (string) $value, $width)));
            }
            $sheet->getRowDimension($index + 5)->setRowHeight($height);
        }
        $sheet->getPageMargins()->setLeft(10 / 25.4)->setRight(10 / 25.4)->setTop(10 / 25.4)->setBottom(10 / 25.4);
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A3)->setFitToWidth(1)->setFitToHeight(0)->setPrintArea('A1:M'.$last)->setRowsToRepeatAtTopByStartAndEnd(1, 4);
        ob_start();
        try {
            (new Xlsx($book))->save('php://output');

            return ob_get_contents();
        } finally {
            ob_end_clean();
            $book->disconnectWorksheets();
        }
    }

    /** Break text using the embedded font metrics, including long unbroken identifiers. */
    private function lines(\TCPDF $pdf, string $text, float $width): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                $candidate = $line === '' ? $word : $line.' '.$word;
                if ($pdf->GetStringWidth($candidate) <= $width - 1.5) {
                    $line = $candidate;

                    continue;
                }
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                foreach (mb_str_split($word) as $character) {
                    if ($line !== '' && $pdf->GetStringWidth($line.$character) > $width - 1.5) {
                        $lines[] = $line;
                        $line = '';
                    }
                    $line .= $character;
                }
            }
            $lines[] = $line;
        }

        return $lines;
    }

    private function textLines(\TCPDF $pdf, array $lines, float $x, float $y, float $width, float $lineHeight, string $align): void
    {
        foreach ($lines as $index => $line) {
            $pdf->SetXY($x, $y + $index * $lineHeight);
            $pdf->Cell($width, $lineHeight, $line, 0, 0, $align);
        }
    }

    public function pdf(Collection $items, array $organization = []): string
    {
        $book = $this->template();
        $sheet = $book->getActiveSheet();
        $headings = $sheet->rangeToArray('A4:M4')[0];
        $pixels = array_map(fn ($col) => $sheet->getColumnDimension($col)->getWidth() * 7 + 5, range('A', 'M'));
        $scale = 400 / array_sum($pixels);
        $widths = array_map(fn ($width) => $width * $scale, $pixels);
        $book->disconnectWorksheets();
        $pdf = app(PdfRenderer::class)->registryDocument();
        $title = $this->title($organization);
        $pdf->SetTitle($title);
        $pdf->SetAutoPageBreak(false, 10);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetLineWidth(0.15);
        $pdf->setCellPaddings(.5, .1, .5, .1);
        $bodySize = 11 * $scale / (25.4 / 96);
        $headerSize = 14 * $scale / (25.4 / 96);
        $bodyHeight = 20 * $scale;
        $lineHeight = $bodySize * 25.4 / 72 * 1.18;
        $headerLineHeight = $headerSize * 25.4 / 72 * 1.18;
        $titleX = 10 + array_sum(array_slice($widths, 0, 6));
        $header = function () use ($pdf, $widths, $headings, $title, $titleX, $headerSize, $bodySize, $scale, $headerLineHeight): void {
            $pdf->SetFont('liberationserif', '', $headerSize, resource_path('fonts/liberationserif.json'));
            $titleWidth = 410 - $titleX;
            $textWidth = $pdf->GetStringWidth($title);
            if ($textWidth > $titleWidth - 1.5) {
                $pdf->SetFontSize($headerSize * ($titleWidth - 1.5) / $textWidth);
            }
            $titleHeight = 25 * $scale;
            $this->textLines($pdf, [$title], $titleX, 10 + 25 * $scale, $titleWidth, $headerLineHeight, 'L');
            $pdf->SetFontSize($headerSize);
            $y = 10 + 50 * $scale + $titleHeight;
            $cells = [];
            foreach ($headings as $i => $text) {
                $cells[] = $this->lines($pdf, $text, $widths[$i]);
            }
            $height = max(75 * $scale, max(array_map('count', $cells)) * $headerLineHeight + .4);
            $x = 10;
            foreach ($cells as $i => $lines) {
                $pdf->Rect($x, $y, $widths[$i], $height);
                $this->textLines($pdf, $lines, $x, $y + .2, $widths[$i], $headerLineHeight, 'C');
                $x += $widths[$i];
            }
            $pdf->SetFont('liberationsans', '', $bodySize, resource_path('fonts/liberationsans.json'));
            $pdf->SetXY(10, $y + $height);
        };
        $header();
        $rows = $items->values()->map(fn ($g, $i) => $this->row($g, $i + 1));
        // Preserve the blank ruled rows from the supplied form (rows 5–60).
        while ($rows->count() < 56) {
            $rows->push(array_fill(0, 13, ''));
        }
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $i => $text) {
                $cells[] = $this->lines($pdf, (string) $text, $widths[$i]);
            }
            $height = max($bodyHeight, max(array_map('count', $cells)) * $lineHeight + .4);
            if ($pdf->GetY() + $height > 287) {
                $pdf->AddPage();
                $header();
            }
            $x = 10;
            $y = $pdf->GetY();
            foreach ($cells as $i => $lines) {
                $pdf->Rect($x, $y, $widths[$i], $height);
                $this->textLines($pdf, $lines, $x, $y + .2, $widths[$i], $lineHeight, $i === 0 ? 'C' : 'L');
                $x += $widths[$i];
            }
            $pdf->SetXY(10, $y + $height);
        }

        return $pdf->Output('registry.pdf', 'S');
    }
}
