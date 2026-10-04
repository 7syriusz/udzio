<?php

namespace App\Domain\Organization\Access;

use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\OrganizationHierarchy;
use App\Models\User;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * What a technical process may do under SystemAuthority (E3.7b, Z-037): a named purpose, its basis, the
 * permissions it needs and the units it works in (each with its descendants at the moment). A process started
 * for an account (export, recurring report) names it in `onBehalfOf`: every decision then also requires that
 * account's current permission, so the process never reaches beyond the person who ordered it.
 */
class SystemPurpose
{
    /** @var list<Permission> */
    public readonly array $permissions;

    /** @var list<int> */
    public readonly array $organizationIds;

    /**
     * @param  list<Permission>  $permissions
     * @param  list<Organization|int>  $organizations  roots of the scope
     */
    public function __construct(
        public readonly string $purpose,
        public readonly string $basis,
        array $permissions,
        array $organizations,
        public readonly ?User $onBehalfOf = null,
    ) {
        if (trim($purpose) === '' || trim($basis) === '') {
            throw new InvalidArgumentException('System authority requires a purpose and a basis.');
        }
        if ($permissions === [] || $organizations === []) {
            throw new InvalidArgumentException('System authority requires declared permissions and a declared scope.');
        }
        $this->permissions = array_values($permissions);
        $this->organizationIds = array_values(array_map(fn (Organization|int $organization) => $organization instanceof Organization ? $organization->id : $organization, $organizations));
    }

    public function allows(Permission $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** Whether the unit is one of the declared roots or below one of them at the moment. */
    public function covers(Organization $target, DateTimeInterface $at): bool
    {
        return in_array($target->id, $this->coveredOrganizationIds($at), true);
    }

    /** @return list<int> */
    public function coveredOrganizationIds(DateTimeInterface $at): array
    {
        $hierarchy = app(OrganizationHierarchy::class);
        $ids = $this->organizationIds;
        foreach (Organization::query()->whereKey($this->organizationIds)->get() as $root) {
            $ids = [...$ids, ...$hierarchy->descendantsAt($root, $at)->modelKeys()];
        }

        return array_values(array_unique($ids));
    }

    /** @return array<string, mixed> what the audit records about the purpose */
    public function describe(): array
    {
        return [
            'purpose' => $this->purpose,
            'basis' => $this->basis,
            'permissions' => array_map(fn (Permission $permission) => $permission->value, $this->permissions),
            'organizations' => Organization::query()->whereKey($this->organizationIds)->orderBy('id')->pluck('public_id')->all(),
            'on_behalf_of_account_id' => $this->onBehalfOf?->getKey(),
        ];
    }
}
