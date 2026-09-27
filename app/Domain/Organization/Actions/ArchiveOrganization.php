<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\AuditReason;
use Illuminate\Support\Facades\DB;

final class ArchiveOrganization
{
    public function __construct(private readonly AuditReason $reason) {}

    public function handle(Organization $organization, string $reason): Organization
    {
        return $this->reason->because($reason, fn () => DB::transaction(function () use ($organization): Organization {
            $current = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();

            if ($current->status === OrganizationStatus::Archived) {
                return $current;
            }

            $current->status = OrganizationStatus::Archived;
            $current->archived_at = now('UTC');
            $current->save();

            return $current;
        }));
    }
}
