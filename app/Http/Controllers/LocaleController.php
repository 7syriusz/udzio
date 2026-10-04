<?php

namespace App\Http\Controllers;

use App\Domain\Platform\Localization\LocaleResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Explicit language choice — for the session, and for the account when signed in (E3.6b). */
class LocaleController extends Controller
{
    public function update(Request $request, LocaleResolver $locales): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', 'string', Rule::in($locales->supported())]]);
        $locales->choose($data['locale'], $request->session(), $request->user());

        return back();
    }
}
