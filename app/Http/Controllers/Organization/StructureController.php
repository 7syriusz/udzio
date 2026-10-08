<?php

namespace App\Http\Controllers\Organization;

use App\Domain\Organization\Access\DataVisibility;
use App\Domain\Organization\Actions\ArchiveOrganization;
use App\Domain\Organization\Actions\CreateOrganizationUnit;
use App\Domain\Organization\Actions\MoveOrganization;
use App\Domain\Organization\Actions\RenameOrganization;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationParent;
use App\Domain\Organization\OrganizationHierarchy;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Actions on the structure (E3.10b, pattern E3.10f): each chosen in "Zarządzaj" opens its own screen with "Anuluj"
 * (back without changes) and one action button. A unit is read through DataVisibility (outside the account's view:
 * 404); the domain action decides and audits. The audit reason of creating, renaming and moving is recorded
 * automatically; archiving asks for it.
 */
class StructureController extends Controller
{
    public function __construct(
        private readonly DataVisibility $visibility,
        private readonly StructureScreen $screen,
    ) {}

    public function createUnit(Request $request, string $organization): View
    {
        return view('organizations.unit-create', ['unit' => $this->find($request, $organization)]);
    }

    public function storeUnit(Request $request, string $organization, CreateOrganizationUnit $create): RedirectResponse
    {
        $parent = $this->find($request, $organization);
        $data = $request->validate(['name' => ['required', 'string', 'max:200']], ['name.required' => __('organization.screens.unit_name_required')]);
        $unit = $create->handle($parent, $data['name'], 'unit created on the organization screen');

        return redirect()->route('organizations.show', $parent->public_id)->with('status', __('organization.screens.unit_created', ['name' => $unit->name]));
    }

    public function editName(Request $request, string $organization): View
    {
        $unit = $this->find($request, $organization);

        return view('organizations.rename', ['unit' => $unit, 'isRoot' => $unit->isRootAt(CarbonImmutable::now('UTC'))]);
    }

    public function rename(Request $request, string $organization, RenameOrganization $rename): RedirectResponse
    {
        $unit = $this->find($request, $organization);
        $data = $request->validate(['name' => ['required', 'string', 'max:200']], ['name.required' => __('organization.screens.new_name_required')]);
        $renamed = $rename->handle($unit, $data['name'], 'renamed on the organization screen');

        return redirect()->route('organizations.show', $unit->public_id)->with('status', __('organization.screens.renamed', ['name' => $renamed->name]));
    }

    public function chooseTarget(Request $request, string $organization): View
    {
        $unit = $this->find($request, $organization);

        return view('organizations.move', ['unit' => $unit, 'from' => $this->screen->pathLabel($this->screen->parentOf($unit) ?? $unit), 'targets' => $this->screen->moveTargets($request->user(), $unit)]);
    }

    public function confirmMove(Request $request, string $organization): View|RedirectResponse
    {
        $unit = $this->find($request, $organization);
        $data = $request->validate(['parent' => ['required', 'string']], ['parent.required' => __('organization.screens.move_choose_required')]);
        $target = collect($this->screen->moveTargets($request->user(), $unit))->first(fn (array $option) => $option['unit']->public_id === $data['parent']);
        if ($target === null) {
            throw ValidationException::withMessages(['parent' => __('organization.screens.move_choose_required')]);
        }

        return view('organizations.move-confirm', [
            'unit' => $unit,
            'from' => $this->screen->pathLabel($this->screen->parentOf($unit)),
            'fromName' => $this->screen->parentOf($unit)->name,
            'to' => $target['path'],
            'target' => $target['unit'],
            'effect' => $this->screen->moveEffect($unit, $target['unit']),
        ]);
    }

    public function move(Request $request, string $organization, MoveOrganization $move): RedirectResponse
    {
        $unit = $this->find($request, $organization);
        $data = $request->validate(['parent' => ['required', 'string']]);
        $target = $this->visibility->findOrganization($request->user(), $data['parent']);
        $move->handle($unit, $target, 'moved on the organization screen');

        return redirect()->route('organizations.show', $unit->public_id)->with('status', __('organization.screens.moved', ['name' => $unit->name, 'target' => $target->name]));
    }

    public function confirmArchive(Request $request, string $organization): View
    {
        $unit = $this->find($request, $organization);

        return view('organizations.archive', [
            'unit' => $unit,
            'isRoot' => $unit->isRootAt(CarbonImmutable::now('UTC')),
            'subUnits' => $this->screen->activeSubUnits($unit),
        ]);
    }

    public function archive(Request $request, string $organization, ArchiveOrganization $archive): RedirectResponse
    {
        $unit = $this->find($request, $organization);
        $isRoot = $unit->isRootAt(CarbonImmutable::now('UTC'));
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000'], 'confirmation' => [$isRoot ? 'required' : 'nullable', 'string']], [
            'reason.required' => __('organization.screens.archive_reason_required'),
            'confirmation.required' => __('organization.screens.archive_confirmation_required'),
        ]);
        if ($isRoot && trim($data['confirmation']) !== $unit->name) {
            throw ValidationException::withMessages(['confirmation' => __('organization.screens.archive_confirmation_mismatch')]);
        }
        $parent = $this->screen->parentOf($unit);
        $archive->handle($unit, $data['reason'], $data['confirmation'] ?? null);

        return ($parent === null ? redirect()->route('organizations.index') : redirect()->route('organizations.show', $parent->public_id))
            ->with('status', __($isRoot ? 'organization.screens.archived_organization' : 'organization.screens.archived_unit', ['name' => $unit->name]));
    }

    /** Archived units that were below this unit when they were archived (needs structure.history.view today). */
    public function archived(Request $request, string $organization, OrganizationHierarchy $hierarchy): View
    {
        $unit = $this->find($request, $organization);
        $archived = $this->visibility->archivedOrganizations($request->user())->orderBy('name')->get()
            ->filter(fn (Organization $past) => $hierarchy->ancestorsAt($past, $past->archived_at->subMicrosecond())->contains('id', $unit->id))
            ->map(fn (Organization $past) => ['unit' => $past, 'place' => $this->placeAtArchiving($past, $hierarchy)])->values();

        return view('organizations.archived', ['unit' => $unit, 'archived' => $archived]);
    }

    /** History of one archived unit: where it was and when, and when it was archived. */
    public function history(Request $request, string $archived, OrganizationHierarchy $hierarchy): View
    {
        $past = $this->visibility->findArchivedOrganization($request->user(), $archived);
        $periods = OrganizationParent::query()->where('organization_id', $past->id)->with('parent')->orderBy('valid_from')->get();
        // Back to the archived units of the nearest unit above that the account still sees.
        $visible = $this->visibility->organizations($request->user())->pluck('id')->all();
        $back = $hierarchy->ancestorsAt($past, $past->archived_at->subMicrosecond())->first(fn (Organization $above) => in_array($above->id, $visible, true));

        return view('organizations.history', ['unit' => $past, 'place' => $this->placeAtArchiving($past, $hierarchy), 'periods' => $periods, 'back' => $back]);
    }

    private function placeAtArchiving(Organization $past, OrganizationHierarchy $hierarchy): string
    {
        return $hierarchy->ancestorsAt($past, $past->archived_at->subMicrosecond())->reverse()->pluck('name')->implode(StructureScreen::PATH_SEPARATOR);
    }

    private function find(Request $request, string $organization): Organization
    {
        return $this->visibility->findOrganization($request->user(), $organization);
    }
}
