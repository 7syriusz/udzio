<?php

namespace App\Domain\Organization\Enums;

/**
 * Permissions over the whole platform (E3.8b), separate from organization permissions (Permission): no
 * organization role carries them, so a founder or administrator of an organization never reaches the platform.
 * Every one is privileged — it works only with a verified e-mail and confirmed MFA.
 */
enum PlatformPermission: string
{
    /** Granting and revoking platform roles. */
    case AdministratorsManage = 'platform.administrators.manage';
    /** Resetting another account's MFA after confirming that person's identity. */
    case MfaReset = 'platform.mfa.reset';
    /**
     * Creating the first platform administrator. Never part of a role: only the installation process holds it,
     * under SystemAuthority, and only until the installation is completed.
     */
    case InstallFirstAdministrator = 'platform.install';
    /**
     * Emergency MFA reset from the server console (E3.8d, Z-040). Never part of a role: only a console process
     * started by someone with administrative access to the server holds it, under SystemAuthority. It removes
     * MFA and sessions of one account and grants nothing.
     */
    case EmergencyMfaReset = 'platform.emergency.mfa_reset';

    public function label(): string
    {
        return __('permissions.'.$this->value);
    }
}
