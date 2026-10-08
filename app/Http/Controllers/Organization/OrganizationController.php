<?php

namespace App\Http\Controllers\Organization;

use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Access\DataVisibility;
use App\Domain\Organization\Access\PrivilegedAccessPolicy;
use App\Domain\Organization\Actions\FoundOrganization;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\RoleAssignment;
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
 * Organizations of the signed-in account (E3.10b, pattern E3.10f): the list, founding a new one and one unit with its
 * actions ("Zarządzaj") and sub-units. Reads go through DataVisibility (outside the account's view: 404, audited);
 * every change goes through the centrally authorized domain actions (StructureController).
 */
class OrganizationController extends Controller
{
    public function __construct(
        private readonly DataVisibility $visibility,
        private readonly AccessDecider $decider,
        private readonly StructureScreen $screen,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $visible = $this->visibility->organizations($user)->orderBy('name')->get();
        $parents = $this->screen->currentParents($visible->modelKeys());
        $topmost = $visible->filter(fn (Organization $organization) => ! $visible->contains('id', $parents[$organization->id] ?? null))->values();

        return view('organizations.index', [
            'organizations' => $topmost,
            'awaitingMfa' => $this->awaitingSecurity($user, PrivilegedAccessPolicy::REASON_MFA_REQUIRED),
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
        ], ['name.required' => __('organization.screens.name_required')]);
        $organization = $found->handle($data['name'], 'organization founded by account holder', $data['request_key']);

        // Without MFA the founder cannot open the organization yet: the list shows one notice with the way forward.
        return $request->user()->hasConfirmedTwoFactor()
            ? redirect()->route('organizations.show', $organization->public_id)->with('status', __('organization.screens.founded', ['name' => $organization->name]))
            : redirect()->route('organizations.index')->with('founded', $organization->name);
    }

    public function show(Request $request, string $organization): View
    {
        $user = $request->user();
        $unit = $this->visibility->findOrganization($user, $organization);
        $now = CarbonImmutable::now('UTC');
        $isRoot = $unit->isRootAt($now);
        $manages = fn (?Organization $target) => $target !== null && Gate::forUser($user)->allows(Permission::StructureManage->value, $target);
        $parent = $this->screen->parentOf($unit);

        return view('organizations.show', [
            'unit' => $unit,
            'isRoot' => $isRoot,
            'path' => $this->screen->visiblePath($user, $unit),
            'subUnits' => $this->screen->visibleSubtree($user, $unit),
            'actions' => [
                'create_unit' => $manages($unit),
                'rename' => $isRoot ? Gate::forUser($user)->allows(Permission::OrganizationManage->value, $unit) : $manages($unit),
                'move' => ! $isRoot && $manages($unit) && $manages($parent) && $this->screen->moveTargets($user, $unit) !== [],
                'archive' => $isRoot ? Gate::forUser($user)->allows(Permission::OrganizationManage->value, $unit) : ($manages($unit) && $manages($parent)),
            ],
            'canSeeArchived' => Gate::forUser($user)->allows(Permission::StructureHistoryView->value, $unit),
        ]);
    }

    /**
     * Organizations where the account holds a role that waits for a security condition (e.g. MFA after founding).
     *
     * @return Collection<int, Organization>
     */
    private function awaitingSecurity(User $user, string $condition): Collection
    {
        $scopes = RoleAssignment::query()->where('user_id', $user->id)->activeAt(CarbonImmutable::now('UTC'))->pluck('scope_organization_id')->unique()->all();

        return Organization::query()->whereKey($scopes)->orderBy('name')->get()
            ->filter(fn (Organization $organization) => $this->decider->decide($user, Permission::OrganizationView, $organization)->reason === $condition)
            ->values();
    }
}
