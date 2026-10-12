<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CardInput
{
    public static function validate(array $input, bool $partial = false): array
    {
        foreach ((is_array($input['data'] ?? null) ? $input['data'] : []) as $value) {
            if (is_string($value) && preg_match('/[\x00-\x1F\x7F]/u', $value)) {
                throw ValidationException::withMessages(['data' => 'Поля не должны содержать переносы строк или управляющие символы.']);
            }
        }

        $rules = [
            'template_key' => ['required', Rule::in(array_keys(config('cards.templates')))],
            'data' => 'required|array:surname,name,patronymic,organization_type,organization_name,director_position,director_name,issue_date',
            'data.surname' => 'required|string|max:80', 'data.name' => 'required|string|max:80', 'data.patronymic' => 'nullable|string|max:80',
            'data.organization_type' => 'required|string|max:60', 'data.organization_name' => 'required|string|max:120',
            'data.director_position' => 'required|string|max:60', 'data.director_name' => 'required|string|max:100',
            'data.issue_date' => 'required|date_format:Y-m-d',
        ];
        if ($partial) {
            foreach ($rules as $key => &$rule) {
                if (str_starts_with($key, 'data.') && is_string($rule)) {
                    $rule = str_replace('required|', 'nullable|', $rule);
                }
            }
            unset($rule);
        }
        $validated = Validator::make($input, $rules)->validate();
        if ($partial) {
            $validated['data'] = array_merge(array_fill_keys(array_keys(self::demo()), ''), array_map(fn ($value) => $value ?? '', $validated['data']));
        }

        return $validated;
    }

    public static function employee(array $input): array
    {
        $validated = self::validate($input, true);
        Validator::make($validated, [
            'data.surname' => 'required|string|max:80',
            'data.name' => 'required|string|max:80',
        ])->validate();

        return $validated;
    }

    public static function settings(array $s): array
    {
        return Validator::make($s, [
            'offset_x' => 'required|numeric|between:-40,40', 'offset_y' => 'required|numeric|between:-40,40', 'font_size' => 'required|integer|between:10,14',
            'fields' => 'sometimes|array:surname,given_names,organization_type,organization_name,director,issue_date',
            'fields.*' => 'array:x,y', 'fields.*.x' => 'required|numeric|between:-40,40', 'fields.*.y' => 'required|numeric|between:-40,40',
        ])->validate();
    }

    public static function demo(): array
    {
        return ['surname' => 'Образцов', 'name' => 'Иван', 'patronymic' => 'Иванович', 'organization_type' => 'ООО ЧОО', 'organization_name' => '«Образец»', 'director_position' => 'Директор', 'director_name' => 'И. И. Образцов', 'issue_date' => '2026-10-09'];
    }
}
