<?php

namespace App\Actions\Fortify;

use App\Domain\Identity\Actions\InvalidateAccountSessions;
use App\Domain\Platform\AuditReason;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

/** Password change by the account holder (screen in E2.8). Other sessions are ended, the current one stays. */
class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    public function __construct(
        private readonly AuditReason $reason,
        private readonly InvalidateAccountSessions $sessions,
    ) {}

    /**
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => $this->passwordRules(),
        ])->validateWithBag('updatePassword');

        DB::transaction(function () use ($user, $input): void {
            $this->reason->because('password changed by account holder', fn () => $user->forceFill(['password' => Hash::make($input['password'])])->save());
            $this->sessions->handle($user, 'other sessions ended after password change', request()->hasSession() ? request()->session()->getId() : null);
        });
    }
}
