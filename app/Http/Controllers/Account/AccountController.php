<?php

namespace App\Http\Controllers\Account;

use App\Domain\Identity\Actions\UpdatePersonDetails;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The account holder's own PERSON data (null while the account is not linked, Z-022). */
class AccountController extends Controller
{
    public function show(Request $request): View
    {
        return view('account.show', ['person' => $request->user()->person, 'review' => $request->user()->openPersonLinkReview]);
    }

    public function update(Request $request, UpdatePersonDetails $update): RedirectResponse
    {
        $person = $request->user()->person ?? abort(404);
        $update->handle($person, $request->only(['given_name', 'family_name', 'birth_date']) + ['birth_date' => null], 'account holder updated own data');

        return redirect()->route('account.show')->with('status', 'Zapisano.');
    }
}
