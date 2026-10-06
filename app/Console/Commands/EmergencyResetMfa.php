<?php

namespace App\Console\Commands;

use App\Domain\Organization\Access\SystemAuthority;
use App\Domain\Organization\Access\SystemPurpose;
use App\Domain\Organization\Actions\EmergencyResetAccountMfa;
use App\Domain\Organization\Enums\PlatformPermission;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\Enums\AuditResult;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Emergency MFA reset from the server console (E3.8d, Z-040): for someone with administrative access to the
 * server when no platform administrator can reset MFA. Names one account, requires a reason, a description of
 * how the holder's identity was confirmed and an explicit confirmation (the account's e-mail typed again).
 * Removes MFA, recovery codes and sessions, tells the holder, grants nothing. Every run is audited, a refused
 * one too.
 */
#[Signature('platform:emergency-mfa-reset {email : Adres e-mail konta} {--reason= : Powód} {--identity-confirmation= : Jak potwierdzono tożsamość właściciela konta} {--confirm= : Adres e-mail konta wpisany ponownie (potwierdzenie)}')]
#[Description('Awaryjny reset uwierzytelniania dwuskładnikowego wskazanego konta (bez nadawania uprawnień).')]
class EmergencyResetMfa extends Command
{
    public function handle(SystemAuthority $authority, EmergencyResetAccountMfa $reset, RecordAudit $audit): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $account = User::query()->where('email', $email)->first();
        if ($account === null) {
            $this->error(__('platform.emergency.account_missing'));

            return self::FAILURE;
        }
        $interactive = $this->input->isInteractive();
        $reason = (string) ($this->option('reason') ?? ($interactive ? $this->ask(__('platform.emergency.ask_reason')) : ''));
        $identity = (string) ($this->option('identity-confirmation') ?? ($interactive ? $this->ask(__('platform.emergency.ask_identity')) : ''));

        $this->warn(__('platform.emergency.summary', ['email' => $account->email, 'name' => trim($account->given_name.' '.$account->family_name)]));
        $confirmation = (string) ($this->option('confirm') ?? ($interactive ? $this->ask(__('platform.emergency.ask_confirm')) : ''));
        $operator = $this->operator();
        if (mb_strtolower(trim($confirmation)) !== $email) {
            $audit->handle('account.mfa_reset', 'account', (string) $account->id, AuditResult::Denied, reason: $reason !== '' ? $reason : null,
                after: ['procedure' => 'emergency', 'operator' => $operator, 'refusal' => 'not_confirmed'], connection: 'audit');
            $this->error(__('platform.emergency.not_confirmed'));

            return self::FAILURE;
        }

        $purpose = new SystemPurpose('platform.emergency_mfa_reset', trim($reason) !== '' ? trim($reason) : 'emergency MFA reset', [PlatformPermission::EmergencyMfaReset], []);
        try {
            $authority->run($purpose, fn () => $reset->handle($account, $identity, $reason, $operator));
        } catch (ValidationException $e) {
            $this->error(__('platform.emergency.invalid'));
            $this->components->bulletList($e->validator->errors()->all());

            return self::FAILURE;
        }

        $this->info(__('platform.emergency.done', ['email' => $account->email]));
        $this->line(__('platform.emergency.next_steps'));

        return self::SUCCESS;
    }

    /** System user and host that ran the command — the closest identity of a server operator. */
    private function operator(): string
    {
        $user = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null;

        return ($user ?? (getenv('USER') ?: 'unknown')).'@'.(gethostname() ?: 'unknown');
    }
}
