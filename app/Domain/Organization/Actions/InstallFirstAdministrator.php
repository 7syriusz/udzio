<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Access\PlatformAccess;
use App\Domain\Organization\Enums\PlatformPermission;
use App\Domain\Organization\Models\PlatformInstallation;
use App\Domain\Organization\Models\PlatformRoleAssignment;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\OperationCorrelation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * One-time installation (E3.8b): creates the account of the first platform administrator, gives it the
 * platform role and closes the procedure — all in one transaction that starts by writing the single installation
 * record, so a concurrent second run waits for it, fails on it and leaves nothing behind. Runs only as a technical process under SystemAuthority
 * with the installation purpose. No default password: the account gets an unusable random one, and the
 * administrator sets their own through the password link sent to the address; the platform role works only
 * after the e-mail is verified and MFA is confirmed (PlatformAccess). No organization is created here.
 */
final class InstallFirstAdministrator
{
    public function __construct(
        private readonly PlatformAccess $access,
        private readonly AuditReason $reason,
        private readonly OperationCorrelation $operation,
        private readonly RecordAudit $audit,
        private readonly ActorContext $context,
    ) {}

    public function handle(string $email, string $givenName, string $familyName): User
    {
        $input = Validator::make([
            'email' => mb_strtolower(trim($email)),
            'given_name' => trim($givenName),
            'family_name' => trim($familyName),
        ], [
            'email' => ['required', 'string', 'email', 'max:254'],
            'given_name' => ['required', 'string', 'max:100', 'regex:/\S/'],
            'family_name' => ['required', 'string', 'max:100', 'regex:/\S/'],
        ])->validate();

        try {
            $administrator = $this->operation->within(fn () => $this->reason->because('platform installation: first administrator', fn () => DB::transaction(function () use ($input): User {
                // An existing account is never promoted: whoever registered the address first must not become administrator.
                // Checked before any write; once installed, the authorization below refuses whatever the address.
                if (! PlatformInstallation::completed() && User::query()->where('email', $input['email'])->exists()) {
                    throw ValidationException::withMessages(['email' => __('platform.install.email_taken')]);
                }
                $this->access->authorize(PlatformPermission::InstallFirstAdministrator);
                $now = CarbonImmutable::now('UTC');
                // The single installation record is written first: it serializes concurrent runs before anything else.
                // A run that loses a deadlock on it is retried and then refused by the authorization above.
                $installation = PlatformInstallation::query()->create([
                    'id' => PlatformInstallation::ID,
                    'installed_at' => $now,
                    'installed_by' => $this->context->current()->identifier,
                ]);
                $account = User::query()->create([...$input, 'password' => Hash::make(Str::random(64))]);
                $role = config('platform.first_administrator_role');
                PlatformRoleAssignment::startPeriod(['user_id' => $account->id, 'role' => $role], $now);
                $installation->recordAdministrator($account);
                $this->audit->handle('platform.installed', PlatformAccess::SUBJECT, 'installation', after: ['administrator_account_id' => $account->id, 'role' => $role]);

                return $account;
            }, attempts: 3)));
        } catch (QueryException $e) {
            if (! PlatformInstallation::completed()) {
                throw $e;
            }
            // A concurrent installation won the single installation record.
            $this->access->refuse(PlatformPermission::InstallFirstAdministrator, 'installation_completed');
        }

        Password::broker()->sendResetLink(['email' => $administrator->email]);
        $administrator->sendEmailVerificationNotification();

        return $administrator;
    }
}
