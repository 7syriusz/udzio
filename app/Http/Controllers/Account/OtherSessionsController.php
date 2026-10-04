<?php

namespace App\Http\Controllers\Account;

use App\Domain\Identity\Actions\InvalidateAccountSessions;
use App\Domain\Platform\AuditReason;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** "Log out other devices": requires the current password; the current session stays. */
class OtherSessionsController extends Controller
{
    public function destroy(Request $request, AuditReason $reason, InvalidateAccountSessions $sessions): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string', 'current_password:web']]);

        DB::transaction(function () use ($request, $reason, $sessions): void {
            // Rehashes the password, so sessions guarded by AuthenticateSession end on their next request.
            $reason->because('account holder logged out other devices', fn () => Auth::logoutOtherDevices($request->input('password')));
            $sessions->handle($request->user(), 'account holder logged out other devices', $request->session()->getId());
        });

        return back()->with('status', __('account.security.other_devices_logged_out'));
    }
}
