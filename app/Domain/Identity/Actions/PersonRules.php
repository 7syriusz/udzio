<?php

namespace App\Domain\Identity\Actions;

final class PersonRules
{
    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'given_name' => ['required', 'string', 'max:100', 'regex:/\S/'],
            'family_name' => ['required', 'string', 'max:100', 'regex:/\S/'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before_or_equal:today'],
        ];
    }
}
