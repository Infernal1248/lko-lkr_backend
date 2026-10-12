<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RegistryData
{
    public static function validate(array $data, bool $required = false): array
    {
        $presence = $required ? 'required' : 'nullable';
        if (! array_key_exists('is_guard', $data)) {
            $data['is_guard'] = false;
        }

        return Validator::make($data, [
            'birth_date' => "$presence|date_format:Y-m-d|before:today",
            'birth_place' => "$presence|string|max:250",
            'position' => "$presence|string|max:180",
            'appointed_at' => "$presence|date_format:Y-m-d",
            'hire_order' => "$presence|string|max:250",
            'is_guard' => 'required|boolean',
            'guard_series' => ($required ? 'required_if:is_guard,true|' : '').'nullable|string|max:30',
            'guard_number' => ($required ? 'required_if:is_guard,true|' : '').'nullable|string|max:40',
            'dismissed_at' => ($required ? 'required_with:dismissal_order|' : '').'nullable|date_format:Y-m-d'.(! empty($data['appointed_at']) ? '|after_or_equal:appointed_at' : ''),
            'dismissal_order' => ($required ? 'required_with:dismissed_at|' : '').'nullable|string|max:250',
        ])->validate();
    }

    public static function complete(?array $data): bool
    {
        try {
            self::validate($data ?? [], true);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }
}
