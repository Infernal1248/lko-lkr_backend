<?php

namespace App\Http\Controllers;

use App\Services\CardInput;
use App\Services\PdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CalibrationController extends Controller
{
    private function templateKey(Request $request): string
    {
        $request->validate(['template_key' => ['sometimes', Rule::in(array_keys(config('cards.templates')))]]);

        return $request->input('template_key', 'ppd-lkr');
    }

    public function layout(Request $request): array
    {
        $key = $this->templateKey($request);
        $template = config('cards.templates.'.$key);
        $sizes = app(PdfRenderer::class)->demoFontSizes($key);
        $texts = ['surname' => 'Образцов', 'given_names' => 'Иван Иванович', 'organization_type' => 'ООО ЧОО', 'organization_name' => '«Образец»', 'director' => 'Директор И. И. Образцов', 'issue_date' => '09.10.2026 г.'];
        $labels = ['surname' => 'Фамилия', 'given_names' => 'Имя и отчество', 'organization_type' => 'Сокращённое наименование', 'organization_name' => 'Название организации', 'director' => 'Руководитель', 'issue_date' => 'Дата выдачи'];

        return ['crop' => $template['crop'], 'width' => $template['width'], 'height' => $template['height'], 'fields' => collect($template['fields'])->map(fn ($box, $key) => ['font_size' => $sizes[$key], 'key' => $key, 'label' => $labels[$key], 'text' => $texts[$key], 'x' => $box[0], 'y' => $box[1], 'width' => $box[2]])->values()];
    }

    public function background(Request $request): mixed
    {
        return response()->file(resource_path('templates/'.$this->templateKey($request).'-calibration.png'));
    }

    public function font(): mixed
    {
        return response()->file(resource_path('fonts/LiberationSerif-Bold.ttf'));
    }

    public function apply(Request $request): array
    {
        $request->validate(['settings' => 'required|array', 'measurements' => 'required|array:surname,given_names,organization_type,organization_name,director,issue_date', 'measurements.*' => 'array:x,y', 'measurements.*.x' => 'required|numeric|between:-40,40', 'measurements.*.y' => 'required|numeric|between:-40,40']);
        $settings = CardInput::settings($request->input('settings'));
        $template = config('cards.templates.'.$this->templateKey($request));
        foreach ($template['fields'] as $key => [$x,$y,$width]) {
            $measurement = $request->input('measurements.'.$key, ['x' => 0, 'y' => 0]);
            $settings['fields'][$key] = ['x' => round(($settings['fields'][$key]['x'] ?? 0) - $measurement['x'], 3), 'y' => round(($settings['fields'][$key]['y'] ?? 0) - $measurement['y'], 3)];
            $correctedX = $x + $settings['offset_x'] + $settings['fields'][$key]['x'];
            $correctedY = $y + $settings['offset_y'] + $settings['fields'][$key]['y'];
            if ($correctedX < 0 || $correctedY < 0 || $correctedX + $width > $template['width'] || $correctedY + 5 > $template['height']) {
                throw ValidationException::withMessages(['measurements' => 'Полученная поправка выводит текст за лист. Проверьте сторону подачи, формат А5 и масштаб 100%.']);
            }
        }

        return CardInput::settings($settings);
    }
}
