<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationParent;
use App\Domain\Platform\AuditReason;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MoveOrganization
{
    public function __construct(private readonly AuditReason $reason) {}

    /** A null parent makes the organization a root. Moves take effect now, never retroactively. */
    public function handle(Organization $organization, ?Organization $parent, string $reason): ?OrganizationParent
    {
        return $this->reason->because($reason, fn () => DB::transaction(function () use ($organization, $parent): ?OrganizationParent {
            // Organizations cannot be deleted: the oldest row is a stable mutex for all graph writes.
            Organization::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $currentOrganization = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $currentParent = $parent === null ? null : Organization::query()->whereKey($parent->getKey())->lockForUpdate()->firstOrFail();
            if ($currentOrganization->status !== OrganizationStatus::Active || ($currentParent !== null && $currentParent->status !== OrganizationStatus::Active)) {
                throw ValidationException::withMessages(['organization' => 'Przenoszona jednostka i nowy rodzic muszą być aktywni.']);
            }

            $seen = [$currentOrganization->id => true];
            $ancestor = $currentParent?->id;
            while ($ancestor !== null) {
                if (isset($seen[$ancestor])) {
                    throw ValidationException::withMessages(['parent' => 'Przeniesienie utworzyłoby cykl w strukturze.']);
                }
                $seen[$ancestor] = true;
                $ancestor = OrganizationParent::query()->where('organization_id', $ancestor)->whereNull('valid_to')->lockForUpdate()->first()?->parent_id;
            }

            $previous = OrganizationParent::query()->where('organization_id', $organization->id)->whereNull('valid_to')->lockForUpdate()->first();
            if ($previous?->parent_id === $currentParent?->id) {
                return $previous;
            }
            $at = CarbonImmutable::now('UTC');
            $previous?->end($at);

            return $currentParent === null ? null : OrganizationParent::startPeriod([
                'organization_id' => $currentOrganization->id,
                'parent_id' => $currentParent->id,
            ], $at);
        }, attempts: 3));
    }
}
