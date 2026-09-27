<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Models\Organization;

final class CreateOrganization
{
    public function handle(string $name): Organization
    {
        return Organization::query()->create(['name' => OrganizationName::validate($name)]);
    }
}
