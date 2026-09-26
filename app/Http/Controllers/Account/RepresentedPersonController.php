<?php

namespace App\Http\Controllers\Account;

use App\Domain\Identity\Actions\ActOnBehalf;
use App\Domain\Identity\Actions\UpdatePersonDetails;
use App\Domain\Identity\Enums\RepresentationScope;
use App\Domain\Identity\Models\Person;
use App\Domain\Identity\Models\Representation;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** People the account holder represents (E2.7). Without the needed scope the person "does not exist" (404). */
class RepresentedPersonController extends Controller
{
    public function index(Request $request): View
    {
        $representations = $request->user()->person_id === null ? collect() : Representation::query()
            ->where('representative_person_id', $request->user()->person_id)
            ->activeAt(now('UTC'))->with('represented')->orderBy('id')->get();

        return view('account.represented', ['representations' => $representations]);
    }

    public function show(Request $request, Person $person, ActOnBehalf $acting): View
    {
        $this->authorizeScope($request, $person, RepresentationScope::ProfileView, $acting);

        return view('account.represented-person', [
            'person' => $person,
            'canUpdate' => $acting->allows($request->user(), $person, RepresentationScope::ProfileUpdate),
        ]);
    }

    public function update(Request $request, Person $person, ActOnBehalf $acting, UpdatePersonDetails $update): RedirectResponse
    {
        $this->authorizeScope($request, $person, RepresentationScope::ProfileUpdate, $acting);
        $acting->handle($request->user(), $person, RepresentationScope::ProfileUpdate,
            fn () => $update->handle($person, $request->only(['given_name', 'family_name', 'birth_date']) + ['birth_date' => null], 'representative updated data'));

        return redirect()->route('account.represented.show', $person)->with('status', 'Zapisano.');
    }

    private function authorizeScope(Request $request, Person $person, RepresentationScope $scope, ActOnBehalf $acting): void
    {
        if ($request->user()->person_id === $person->id || ! $acting->allows($request->user(), $person, $scope)) {
            throw (new AccessDenied('person', $person->public_id, null, 'representation.'.$scope->value))->hideAsNotFound();
        }
    }
}
