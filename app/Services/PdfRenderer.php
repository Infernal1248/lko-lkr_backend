<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

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
        foreach ($this->layout($pdf, $template, $data, $settings) as $line) {
            $pdf->SetFont('liberationserif', 'B', $line['size'], resource_path('fonts/liberationserifb.json'));
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY($line['x'], $line['y']);
            $pdf->Cell($line['width'], 5, $line['text'], 0, 0, 'C');
        }
        if ($preview) {
            $pdf->SetFont('liberationserif', 'B', 8, resource_path('fonts/liberationserifb.json'));
            $pdf->SetTextColor(145, 145, 145);
            $pdf->SetXY(55, 131);
            $pdf->Cell(30, 4, 'Предпросмотр', 0, 0, 'C');
        }

        return $pdf->Output('card.pdf', 'S');
    }

    private function layout(\TCPDF $pdf, array $template, array $data, array $settings): array
    {
        $lines = ['surname' => $data['surname'], 'given_names' => trim($data['name'].' '.($data['patronymic'] ?? '')), 'organization_type' => $data['organization_type'], 'organization_name' => $data['organization_name'], 'director' => trim($data['director_position'].' '.$data['director_name']), 'issue_date' => ($data['issue_date'] ? Carbon::parse($data['issue_date'])->format('d.m.Y').' г.' : '')];
        $layout = [];
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
            $ascent = $pdf->getFontAscent('liberationserif', 'B', $size);
            $descent = $pdf->getFontDescent('liberationserif', 'B', $size);
            $height = max(5, $pdf->getCellHeight($size * 25.4 / 72));
            $layout[] = compact('text', 'size', 'x', 'y', 'width') + [
                'left' => $x + ($width - $pdf->GetStringWidth($text)) / 2,
                'baseline' => $y + ($height - $ascent - $descent) / 2 + $ascent,
            ];
        }

        return $layout;
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

    public function registryDocument(): \TCPDF
    {
        $pdf = $this->document(['width' => 420, 'height' => 297]);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->SetXY(10, 10);

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
        abort_unless(extension_loaded('gd') && function_exists('imagettftext'), 503, 'Для предпросмотра включите расширение PHP GD с FreeType в панели хостинга.');
        $template = config("cards.templates.$key");
        abort_unless($template, 422, 'Неизвестный бланк.');
        $pdf = $this->document($template);
        $layout = $this->layout($pdf, $template, $data, $settings);
        [$cropX, $cropY, $cropWidth, $cropHeight] = $template['crop'];
        $scale = 1600 / $template['width'];
        $image = imagecreatetruecolor((int) round($cropWidth * $scale), (int) round($cropHeight * $scale));
        $source = imagecreatefrompng(resource_path($template['preview_image']));
        try {
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
            imagecopyresampled($image, $source, (int) round(-$cropX * $scale), (int) round(-$cropY * $scale), 0, 0,
                (int) round($template['width'] * $scale), (int) round($template['height'] * $scale), imagesx($source), imagesy($source));
            $black = imagecolorallocate($image, 0, 0, 0);
            foreach ($layout as $line) {
                $pdf->SetFont('liberationserif', 'B', $line['size'], resource_path('fonts/liberationserifb.json'));
                $x = $line['left'];
                foreach (preg_split('//u', $line['text'], -1, PREG_SPLIT_NO_EMPTY) as $character) {
                    imagettftext($image, $line['size'] * $scale * 25.4 / 96, 0,
                        (int) round(($x - $cropX) * $scale), (int) round(($line['baseline'] - $cropY) * $scale),
                        $black, resource_path('fonts/LiberationSerif-Bold.ttf'), $character);
                    $x += $pdf->GetStringWidth($character);
                }
            }
            imagettftext($image, 8 * $scale * 25.4 / 96, 0, (int) round((57 - $cropX) * $scale),
                (int) round((134 - $cropY) * $scale), imagecolorallocate($image, 145, 145, 145),
                resource_path('fonts/LiberationSerif-Bold.ttf'), 'Предпросмотр');
            ob_start();
            try {
                imagepng($image);

                return ob_get_contents();
            } finally {
                ob_end_clean();
            }
        } finally {
            imagedestroy($source);
            imagedestroy($image);
        }
    }
}
