<?php

namespace App\Actions\Fortify;

use App\Domain\Identity\Actions\InvalidateAccountSessions;
use App\Domain\Platform\AuditReason;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(
        private readonly AuditReason $reason,
        private readonly InvalidateAccountSessions $sessions,
    ) {}

    /**
     * Sets the new password and ends every session of the account: whoever used the old password is out.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        DB::transaction(function () use ($user, $input): void {
            $this->reason->because('password reset by e-mail link', fn () => $user->forceFill(['password' => Hash::make($input['password'])])->save());
            $this->sessions->handle($user, 'sessions ended after password reset');
        });
    }
}
