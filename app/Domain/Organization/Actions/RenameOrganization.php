<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\AuditReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RenameOrganization
{
    public function __construct(private readonly AuditReason $reason) {}

    public function handle(Organization $organization, string $name, string $reason): Organization
    {
        $name = OrganizationName::validate($name);

        return $this->reason->because($reason, fn () => DB::transaction(function () use ($organization, $name): Organization {
            $current = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();

            if ($current->status === OrganizationStatus::Archived) {
                throw ValidationException::withMessages(['organization' => __('organization.validation.archived_cannot_be_renamed')]);
            }

            $current->name = $name;
            $current->save();

            return $current;
        }));
    }
}
