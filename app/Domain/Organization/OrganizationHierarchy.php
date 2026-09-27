<?php

namespace App\Domain\Organization;

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationParent;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Historical structure only: it never grants access or implies role inheritance. */
final class OrganizationHierarchy
{
    /** @return Collection<int, Organization> Nearest parent first. */
    public function ancestorsAt(Organization $organization, DateTimeInterface $at): Collection
    {
        return DB::transaction(function () use ($organization, $at): Collection {
            $result = new Collection;
            $seen = [$organization->id => true];
            $id = $organization->id;
            while ($period = OrganizationParent::query()->where('organization_id', $id)->activeAt($at)->first()) {
                $id = $period->parent_id;
                if (isset($seen[$id])) {
                    throw new LogicException('Corrupt organization hierarchy: cycle detected.');
                }
                $seen[$id] = true;
                $result->push($period->parent);
            }

            return $result;
        });
    }

    /** @return Collection<int, Organization> Breadth first, siblings ordered by internal ID. */
    public function descendantsAt(Organization $organization, DateTimeInterface $at): Collection
    {
        return DB::transaction(function () use ($organization, $at): Collection {
            $result = new Collection;
            $seen = [$organization->id => true];
            $level = [$organization->id];
            while ($level !== []) {
                $periods = OrganizationParent::query()->whereIn('parent_id', $level)->activeAt($at)->with('organization')->orderBy('organization_id')->get();
                $level = [];
                foreach ($periods as $period) {
                    if (isset($seen[$period->organization_id])) {
                        throw new LogicException('Corrupt organization hierarchy: cycle detected.');
                    }
                    $seen[$period->organization_id] = true;
                    $level[] = $period->organization_id;
                    $result->push($period->organization);
                }
            }

            return $result;
        });
    }
}
