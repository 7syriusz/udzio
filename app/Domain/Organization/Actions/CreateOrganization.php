<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Models\Organization;

/**
 * Creates the ORGANIZATION record only — no authorization of its own. Reached through FoundOrganization (founder)
 * or CreateOrganizationUnit (structure.manage); never from HTTP directly (ArchitectureTest, Z-040).
 */
final class CreateOrganization
{
    public function handle(string $name): Organization
    {
        return Organization::query()->create(['name' => OrganizationName::validate($name)]);
    }
}
