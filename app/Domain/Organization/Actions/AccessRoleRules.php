<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Enums\Permission;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Shared validation of role name and permission set for internal callers. */
final class AccessRoleRules
{
    public static function name(string $name): string
    {
        return Validator::make(['name' => trim($name)], [
            'name' => ['required', 'string', 'max:100', 'regex:/\S/u'],
        ])->validate()['name'];
    }

    /**
     * Unknown permissions are refused; the stored set is unique and sorted.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public static function permissions(array $permissions): array
    {
        $validated = Validator::make(['permissions' => $permissions], [
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['required', 'string', Rule::enum(Permission::class)],
        ])->validate()['permissions'];
        $unique = array_values(array_unique($validated));
        sort($unique);

        return $unique;
    }
}
