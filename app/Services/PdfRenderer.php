<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class PdfRenderer
{
    public function render(string $key, array $data, array $settings, bool $proof = false, bool $preview = false): string
    {
        $template = config("cards.templates.$key");
        abort_unless($template, 422, 'Неизвестный бланк.');
        $pdf = $this->document($template);
        if ($preview || $proof) {
            $pdf->Image(resource_path($template['preview_image']), 0, 0, $template['width'], $template['height']);
        }
        if ($proof) {
            $pdf->SetFont('liberationserif', 'B', 10, resource_path('fonts/liberationserifb.json'));
            $pdf->SetXY(4, 4);
            $pdf->Cell(100, 5, 'ПРОБНЫЙ МАКЕТ · А5 · 100%');

            return $pdf->Output('proof.pdf', 'S');
        }
        $lines = ['surname' => $data['surname'], 'given_names' => trim($data['name'].' '.($data['patronymic'] ?? '')), 'organization_type' => $data['organization_type'], 'organization_name' => $data['organization_name'], 'director' => trim($data['director_position'].' '.$data['director_name']), 'issue_date' => ($data['issue_date'] ? Carbon::parse($data['issue_date'])->format('d.m.Y').' г.' : '')];
        foreach ($template['fields'] as $field => [$x,$y,$width]) {
            $size = (float) $settings['font_size'];
            $text = $lines[$field];
            do {
                $pdf->SetFont('liberationserif', 'B', $size, resource_path('fonts/liberationserifb.json'));
                if ($pdf->GetStringWidth($text) <= $width - 1) {
                    break;
                } $size -= .5;
            } while ($size >= 10);
            if ($size < 10) {
                throw ValidationException::withMessages(['data.'.$field => 'Строка «'.$text.'» не помещается при кегле 10. Сократите запись.']);
            }
            $x += (float) $settings['offset_x'] + (float) ($settings['fields'][$field]['x'] ?? 0);
            $y += (float) $settings['offset_y'] + (float) ($settings['fields'][$field]['y'] ?? 0);
            if ($x < 0 || $y < 0 || $x + $width > $template['width'] || $y + 5 > $template['height']) {
                throw ValidationException::withMessages(['settings' => 'Смещение выводит текст за пределы листа.']);
            }
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY($x, $y);
            $pdf->Cell($width, 5, $text, 0, 0, 'C');
        }
        if ($preview) {
            $pdf->SetFont('liberationserif', 'B', 8, resource_path('fonts/liberationserifb.json'));
            $pdf->SetTextColor(145, 145, 145);
            $pdf->SetXY(55, 131);
            $pdf->Cell(30, 4, 'Предпросмотр', 0, 0, 'C');
        }

        return $pdf->Output('card.pdf', 'S');
    }

    private function document(array $template): \TCPDF
    {
        if (! defined('K_TCPDF_EXTERNAL_CONFIG')) {
            define('K_TCPDF_EXTERNAL_CONFIG', true);
        }
        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', resource_path('fonts/'));
        }
        if (! defined('PDF_FONT_NAME_MAIN')) {
            define('PDF_FONT_NAME_MAIN', 'liberationserifb');
        }
        if (! defined('K_ALLOWED_PATHS')) {
            define('K_ALLOWED_PATHS', [resource_path()]);
        }
        $pdf = new \TCPDF('L', 'mm', [$template['width'], $template['height']], true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetCreator('Реестр');
        $pdf->SetTitle('Личная карточка работника');
        $pdf->AddPage();

        return $pdf;
    }

    public function demoFontSizes(string $templateKey = 'ppd-lkr'): array
    {
        $template = config('cards.templates.'.$templateKey);
        $pdf = $this->document($template);
        $texts = ['surname' => 'Образцов', 'given_names' => 'Иван Иванович', 'organization_type' => 'ООО ЧОО', 'organization_name' => '«Образец»', 'director' => 'Директор И. И. Образцов', 'issue_date' => '09.10.2026 г.'];
        $sizes = [];
        foreach ($texts as $key => $text) {
            $size = 12;
            do {
                $pdf->SetFont('liberationserif', 'B', $size, resource_path('fonts/liberationserifb.json'));
                if ($pdf->GetStringWidth($text) <= $template['fields'][$key][2] - 1) {
                    break;
                } $size -= .5;
            } while ($size >= 10);
            $sizes[$key] = $size;
        }

        return $sizes;
    }

    public function preview(string $key, array $data, array $settings): string
    {
        $directory = storage_path('app/private/previews/'.Str::uuid());
        mkdir($directory, 0700, true);
        try {
            file_put_contents($directory.'/input.pdf', $this->render($key, $data, $settings, false, true));
            [$cropX, $cropY, $cropWidth, $cropHeight] = config("cards.templates.$key.crop");
            $scale = 1600 / 210;
            $process = new Process([config('cards.pdftoppm'), '-f', '1', '-singlefile', '-scale-to', '1600', '-x', (string) round($cropX * $scale), '-y', (string) round($cropY * $scale), '-W', (string) round($cropWidth * $scale), '-H', (string) round($cropHeight * $scale), '-png', $directory.'/input.pdf', $directory.'/preview']);
            $process->setTimeout(30);
            $process->mustRun();

            return file_get_contents($directory.'/preview.png');
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            } rmdir($directory);
        }
    }
}
