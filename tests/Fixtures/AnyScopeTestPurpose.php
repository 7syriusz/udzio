<?php

namespace Tests\Fixtures;

use App\Domain\Organization\Access\SystemPurpose;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Models\Organization;
use DateTimeInterface;

/**
 * Test setup only: a system purpose covering every unit, so tests can prepare data through the real,
 * centrally authorized actions. Production code always declares a concrete scope (E3.7b).
 */
final class AnyScopeTestPurpose extends SystemPurpose
{
    public function __construct(string $purpose = 'test setup')
    {
        parent::__construct($purpose, 'automated test', Permission::cases(), [0]);
    }

    public function covers(Organization $target, DateTimeInterface $at): bool
    {
        return true;
    }

    public function coveredOrganizationIds(DateTimeInterface $at): array
    {
        return Organization::query()->pluck('id')->all();
    }
}
