<?php

namespace App\Domain\Organization\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

final class AccessRoleName
{
    /** Maps the unique active-name violation to a validation error; rethrows anything else. */
    public static function duplicate(QueryException $e): ValidationException
    {
        if (($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), 'active_name_key')) {
            return ValidationException::withMessages(['name' => 'Organizacja ma już aktywną rolę o tej nazwie.']);
        }
        throw $e;
    }
}
