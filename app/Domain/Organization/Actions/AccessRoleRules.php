<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Enums\AccessRoleStatus;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\OrganizationHierarchy;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Shared validation of role name, permission set and role-granting catalog for internal callers. */
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

    /**
     * Role-granting catalog of a managing role (E3.6a). Each rule names one role of the same organization
     * or a unit below it — or `*`: every role of that organization and the units below it, also roles defined
     * later (E3.10a, Z-043) — and says whether it may be granted below the manager's own scope unit, for at most
     * how many days, and whether the assignment needs approval. A catalog requires `roles.assign`.
     *
     * @param  list<array{role: string, include_descendants?: bool, max_days?: int|null, requires_approval?: bool}>  $rules
     * @param  list<string>  $permissions  normalized permissions of the same role
     * @return list<array{role: string, include_descendants: bool, max_days: int|null, requires_approval: bool}>
     */
    public static function grantRules(array $rules, Organization $owner, array $permissions): array
    {
        if ($rules === []) {
            return [];
        }
        if (! in_array(Permission::RolesAssign->value, $permissions, true)) {
            throw ValidationException::withMessages(['grant_rules' => __('organization.validation.grant_catalog_requires_assign')]);
        }
        $validated = Validator::make(['grant_rules' => $rules], [
            'grant_rules' => ['array'],
            'grant_rules.*.role' => ['required', 'string', 'distinct'],
            'grant_rules.*.include_descendants' => ['sometimes', 'boolean'],
            'grant_rules.*.max_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'grant_rules.*.requires_approval' => ['sometimes', 'boolean'],
        ])->validate()['grant_rules'];

        $allowedOwners = [$owner->id, ...app(OrganizationHierarchy::class)->descendantsAt($owner, now('UTC'))->modelKeys()];
        $normalized = [];
        foreach ($validated as $rule) {
            $role = $rule['role'] === AccessRole::ANY_ROLE ? null : AccessRole::query()->where('public_id', $rule['role'])->first();
            if ($rule['role'] !== AccessRole::ANY_ROLE && ($role === null || $role->status !== AccessRoleStatus::Active || ! in_array($role->organization_id, $allowedOwners, true))) {
                throw ValidationException::withMessages(['grant_rules' => __('organization.validation.grant_catalog_foreign_role')]);
            }
            $normalized[] = [
                'role' => $role?->public_id ?? AccessRole::ANY_ROLE,
                'include_descendants' => (bool) ($rule['include_descendants'] ?? false),
                'max_days' => isset($rule['max_days']) ? (int) $rule['max_days'] : null,
                'requires_approval' => (bool) ($rule['requires_approval'] ?? false),
            ];
        }
        usort($normalized, fn (array $a, array $b) => strcmp($a['role'], $b['role']));

        return $normalized;
    }
}
