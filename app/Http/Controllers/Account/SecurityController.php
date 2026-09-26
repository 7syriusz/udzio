<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** MFA, password change and other devices; the forms post to Fortify and OtherSessionsController. */
class SecurityController extends Controller
{
    public function show(Request $request): View
    {
        return view('account.security', ['user' => $request->user()]);
    }
}
