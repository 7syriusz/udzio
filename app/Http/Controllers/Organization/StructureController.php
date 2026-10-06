<?php

namespace App\Http\Controllers\Organization;

use App\Domain\Organization\Access\DataVisibility;
use App\Domain\Organization\Actions\ArchiveOrganization;
use App\Domain\Organization\Actions\CreateOrganizationUnit;
use App\Domain\Organization\Actions\MoveOrganization;
use App\Domain\Organization\Actions\RenameOrganization;
use App\Domain\Organization\Models\OrganizationParent;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Changes of the structure (E3.10b): a new unit, a new name, a move and archiving. The unit is read through
 * DataVisibility (outside the account's view — 404); the domain action decides and audits the change.
 */
class StructureController extends Controller
{
    public function __construct(private readonly DataVisibility $visibility) {}

    public function storeUnit(Request $request, string $organization, CreateOrganizationUnit $create): RedirectResponse
    {
        $parent = $this->visibility->findOrganization($request->user(), $organization);
        $data = $request->validate(['name' => ['required', 'string', 'max:200'], 'reason' => ['required', 'string', 'max:1000']]);
        $create->handle($parent, $data['name'], $data['reason']);

        return back()->with('status', __('organization.screens.unit_created'));
    }

    public function rename(Request $request, string $organization, RenameOrganization $rename): RedirectResponse
    {
        $unit = $this->visibility->findOrganization($request->user(), $organization);
        $data = $request->validate(['name' => ['required', 'string', 'max:200'], 'reason' => ['required', 'string', 'max:1000']]);
        $rename->handle($unit, $data['name'], $data['reason']);

        return back()->with('status', __('organization.screens.renamed'));
    }

    public function move(Request $request, string $organization, MoveOrganization $move): RedirectResponse
    {
        $unit = $this->visibility->findOrganization($request->user(), $organization);
        $data = $request->validate(['parent' => ['required', 'string'], 'reason' => ['required', 'string', 'max:1000']]);
        $move->handle($unit, $this->visibility->findOrganization($request->user(), $data['parent']), $data['reason']);

        return back()->with('status', __('organization.screens.moved'));
    }

    public function confirmArchive(Request $request, string $organization): View
    {
        $unit = $this->visibility->findOrganization($request->user(), $organization);

        return view('organizations.archive', ['organization' => $unit, 'isRoot' => $unit->isRootAt(CarbonImmutable::now('UTC'))]);
    }

    public function archive(Request $request, string $organization, ArchiveOrganization $archive): RedirectResponse
    {
        $unit = $this->visibility->findOrganization($request->user(), $organization);
        $isRoot = $unit->isRootAt(CarbonImmutable::now('UTC'));
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000'], 'confirmation' => [$isRoot ? 'required' : 'nullable', 'string']]);
        if ($isRoot && trim($data['confirmation']) !== $unit->name) {
            throw ValidationException::withMessages(['confirmation' => __('organization.screens.archive_confirmation_mismatch')]);
        }
        $parent = OrganizationParent::query()->where('organization_id', $unit->id)->activeAt(CarbonImmutable::now('UTC'))->first()?->parent;
        $archive->handle($unit, $data['reason'], $data['confirmation'] ?? null);

        return ($parent === null ? redirect()->route('organizations.index') : redirect()->route('organizations.show', $parent->public_id))
            ->with('status', __('organization.screens.archived', ['name' => $unit->name]));
    }
}
