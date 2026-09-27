<?php

namespace App\Domain\Organization\Actions;

use Illuminate\Support\Facades\Validator;

/** Shared validation for internal actions; HTTP validation alone would not protect other callers. */
final class OrganizationName
{
    public static function validate(string $name): string
    {
        return Validator::make(['name' => trim($name)], [
            'name' => ['required', 'string', 'max:255', 'regex:/\S/u'],
        ])->validate()['name'];
    }
}
