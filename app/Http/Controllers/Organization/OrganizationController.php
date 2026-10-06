<?php

namespace App\Http\Controllers\Organization;

use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Access\DataVisibility;
use App\Domain\Organization\Access\PrivilegedAccessPolicy;
use App\Domain\Organization\Actions\FoundOrganization;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationParent;
use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Organization\OrganizationHierarchy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Organizations of the signed-in account (E3.10b): the list of organizations it may see, founding a new one and
 * one organization with its tree of units. Reads go through DataVisibility (an organization outside the account's
 * view answers 404, audited); every change goes through the centrally authorized domain actions.
 */
class OrganizationController extends Controller
{
    public function __construct(
        private readonly DataVisibility $visibility,
        private readonly AccessDecider $decider,
        private readonly OrganizationHierarchy $hierarchy,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $visible = $this->visibility->organizations($user)->orderBy('name')->get();
        $parents = $this->currentParents($visible->modelKeys());
        $topmost = $visible->filter(fn (Organization $organization) => ! $visible->contains('id', $parents[$organization->id] ?? null))->values();

        return view('organizations.index', [
            'organizations' => $topmost,
            'awaitingMfa' => $this->awaitingSecurity($user, PrivilegedAccessPolicy::REASON_MFA_REQUIRED),
            'hasMfa' => $user->hasConfirmedTwoFactor(),
        ]);
    }

    public function create(): View
    {
        // A new key per displayed form: submitting the same form again founds nothing new (Z-043).
        return view('organizations.create', ['requestKey' => (string) Str::ulid()]);
    }

    public function store(Request $request, FoundOrganization $found): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'request_key' => ['required', 'string', 'between:8,191'],
        ]);
        $organization = $found->handle($data['name'], 'organization founded by account holder', $data['request_key']);

        return redirect()->route('organizations.index')
            ->with('status', __('organization.screens.founded', ['name' => $organization->name]))
            ->with('founded_needs_mfa', ! $request->user()->hasConfirmedTwoFactor());
    }

    public function show(Request $request, string $organization): View
    {
        $user = $request->user();
        $current = $this->visibility->findOrganization($user, $organization);
        $now = CarbonImmutable::now('UTC');
        $visibleIds = $this->visibility->organizations($user)->pluck('id')->all();
        $units = $this->hierarchy->descendantsAt($current, $now)->whereIn('id', $visibleIds)->values();
        $all = collect([$current])->merge($units);
        $ancestors = $this->hierarchy->ancestorsAt($current, $now);
        // Move targets come from the whole visible structure of the organization, not only from this subtree.
        $root = $ancestors->last() ?? $current;
        $structure = collect([$root])->merge($this->hierarchy->descendantsAt($root, $now))->whereIn('id', $visibleIds)->values();
        $parents = $this->currentParents($structure->pluck('id')->merge($all->pluck('id'))->unique()->values()->all());

        return view('organizations.show', [
            'organization' => $current,
            'isRoot' => $current->isRootAt($now),
            'ancestors' => $ancestors->whereIn('id', $visibleIds)->reverse()->values(),
            'tree' => $this->tree($current, $all, $parents),
            'abilities' => $all->mapWithKeys(fn (Organization $unit) => [$unit->id => $this->abilities($user, $unit, $parents, $structure)])->all(),
        ]);
    }

    /**
     * What the account may do with a unit — only to decide which forms to show; every action is authorized again.
     *
     * @param  array<int, int>  $parents
     * @param  Collection<int, Organization>  $structure  visible units of the whole organization
     * @return array{create_unit: bool, rename: bool, move_to: list<Organization>, archive: bool}
     */
    private function abilities(User $user, Organization $unit, array $parents, $structure): array
    {
        $manages = fn (Organization $target) => Gate::forUser($user)->allows(Permission::StructureManage->value, $target);
        $parent = isset($parents[$unit->id]) ? Organization::query()->find($parents[$unit->id]) : null;
        $isRoot = $parent === null;
        $movable = ! $isRoot && $manages($unit) && $manages($parent);
        $excluded = [$unit->id, $parent?->id, ...$this->hierarchy->descendantsAt($unit, CarbonImmutable::now('UTC'))->modelKeys()];

        return [
            'create_unit' => $manages($unit),
            'rename' => $isRoot ? Gate::forUser($user)->allows(Permission::OrganizationManage->value, $unit) : $manages($unit),
            'move_to' => $movable ? $structure->reject(fn (Organization $target) => in_array($target->id, $excluded, true))->filter($manages)->values()->all() : [],
            'archive' => $isRoot ? Gate::forUser($user)->allows(Permission::OrganizationManage->value, $unit) : ($manages($unit) && $manages($parent)),
        ];
    }

    /**
     * @param  Collection<int, Organization>  $all
     * @param  array<int, int>  $parents
     * @return array{unit: Organization, children: list<array<string, mixed>>}
     */
    private function tree(Organization $unit, $all, array $parents): array
    {
        $children = $all->filter(fn (Organization $candidate) => ($parents[$candidate->id] ?? null) === $unit->id)->sortBy('name')->values();

        return ['unit' => $unit, 'children' => $children->map(fn (Organization $child) => $this->tree($child, $all, $parents))->all()];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int> unit ID => current parent ID
     */
    private function currentParents(array $ids): array
    {
        return OrganizationParent::query()->whereIn('organization_id', $ids)->activeAt(CarbonImmutable::now('UTC'))
            ->pluck('parent_id', 'organization_id')->all();
    }

    /**
     * Organizations where the account holds a role that waits for a security condition (e.g. MFA after founding).
     *
     * @return Collection<int, Organization>
     */
    private function awaitingSecurity(User $user, string $condition)
    {
        $scopes = RoleAssignment::query()->where('user_id', $user->id)->activeAt(CarbonImmutable::now('UTC'))->pluck('scope_organization_id')->unique()->all();

        return Organization::query()->whereKey($scopes)->orderBy('name')->get()
            ->filter(fn (Organization $organization) => $this->decider->decide($user, Permission::OrganizationView, $organization)->reason === $condition)
            ->values();
    }
}
