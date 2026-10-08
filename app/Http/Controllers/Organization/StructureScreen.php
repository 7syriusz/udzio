<?php

namespace App\Http\Controllers\Organization;

use App\Domain\Organization\Access\DataVisibility;
use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Enums\ScopeInheritance;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationParent;
use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Organization\OrganizationHierarchy;
use App\Domain\Platform\Enums\RelationStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * What the structure screens show (E3.10f): where a unit is, its visible sub-units, where it may be moved and what a
 * move or archiving would change. Only reads; deciding stays with the domain actions.
 */
final class StructureScreen
{
    public const PATH_SEPARATOR = ' › ';

    public function __construct(
        private readonly DataVisibility $visibility,
        private readonly OrganizationHierarchy $hierarchy,
    ) {}

    /**
     * @param  list<int>  $ids
     * @return array<int, int> unit ID => current parent ID
     */
    public function currentParents(array $ids): array
    {
        return OrganizationParent::query()->whereIn('organization_id', $ids)->activeAt($this->now())->pluck('parent_id', 'organization_id')->all();
    }

    public function parentOf(Organization $unit): ?Organization
    {
        return OrganizationParent::query()->where('organization_id', $unit->id)->activeAt($this->now())->first()?->parent;
    }

    /** @return Collection<int, Organization> visible units above the unit, the top one first */
    public function visiblePath(User $user, Organization $unit): Collection
    {
        $visible = $this->visibility->organizations($user)->pluck('id')->all();

        return $this->hierarchy->ancestorsAt($unit, $this->now())->whereIn('id', $visible)->reverse()->values();
    }

    /** Names of the units above a unit and the unit itself, e.g. "KKP › OKR 3". */
    public function pathLabel(Organization $unit): string
    {
        return $this->hierarchy->ancestorsAt($unit, $this->now())->reverse()->push($unit)->pluck('name')->implode(self::PATH_SEPARATOR);
    }

    /**
     * Visible sub-units in tree order, reached only through visible parents.
     *
     * @return list<array{unit: Organization, depth: int}>
     */
    public function visibleSubtree(User $user, Organization $unit): array
    {
        $visible = $this->visibility->organizations($user)->pluck('id')->all();
        $units = $this->hierarchy->descendantsAt($unit, $this->now())->whereIn('id', $visible)->keyBy('id');
        $parents = $this->currentParents($units->keys()->all());
        $rows = [];
        $walk = function (int $parentId, int $depth) use (&$walk, &$rows, $units, $parents): void {
            foreach ($units->filter(fn (Organization $child) => ($parents[$child->id] ?? null) === $parentId)->sortBy('name') as $child) {
                $rows[] = ['unit' => $child, 'depth' => $depth];
                $walk($child->id, $depth + 1);
            }
        };
        $walk($unit->id, 1);

        return $rows;
    }

    /**
     * Places the unit may move to: visible units of the same organization that the account manages, without the
     * unit, its sub-units and its current parent.
     *
     * @return list<array{unit: Organization, path: string}>
     */
    public function moveTargets(User $user, Organization $unit): array
    {
        $root = $this->hierarchy->ancestorsAt($unit, $this->now())->last() ?? $unit;
        $visible = $this->visibility->organizations($user)->pluck('id')->all();
        $excluded = [$unit->id, $this->parentOf($unit)?->id, ...$this->hierarchy->descendantsAt($unit, $this->now())->modelKeys()];

        return collect([$root])->merge($this->hierarchy->descendantsAt($root, $this->now()))
            ->filter(fn (Organization $target) => in_array($target->id, $visible, true) && ! in_array($target->id, $excluded, true))
            ->filter(fn (Organization $target) => Gate::forUser($user)->allows(Permission::StructureManage->value, $target))
            ->map(fn (Organization $target) => ['unit' => $target, 'path' => $this->pathLabel($target)])
            ->sortBy('path')->values()->all();
    }

    /**
     * What a move changes: the sub-units that move along and how many accounts lose or gain access through roles
     * inherited from the units above (roles given in the unit itself stay).
     *
     * @return array{sub_units: Collection<int, Organization>, losing: int, gaining: int}
     */
    public function moveEffect(Organization $unit, Organization $target): array
    {
        $now = $this->now();
        $from = $this->hierarchy->ancestorsAt($unit, $now)->modelKeys();
        $to = [$target->id, ...$this->hierarchy->ancestorsAt($target, $now)->modelKeys()];
        $accounts = fn (array $scopes) => $scopes === [] ? 0 : RoleAssignment::query()->whereIn('scope_organization_id', $scopes)
            ->where('scope_inheritance', ScopeInheritance::UnitAndDescendants)->where('status', RelationStatus::Active)
            ->activeAt($now)->distinct()->count('user_id');

        return [
            'sub_units' => $this->activeSubUnits($unit),
            'losing' => $accounts(array_values(array_diff($from, $to))),
            'gaining' => $accounts(array_values(array_diff($to, $from))),
        ];
    }

    /** @return Collection<int, Organization> active units below the unit (they move or are archived with it) */
    public function activeSubUnits(Organization $unit): Collection
    {
        return $this->hierarchy->descendantsAt($unit, $this->now())->filter(fn (Organization $sub) => $sub->status === OrganizationStatus::Active)->values();
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }
}
