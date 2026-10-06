<?php

namespace App\Console\Commands;

use App\Domain\Organization\Access\SystemAuthority;
use App\Domain\Organization\Access\SystemPurpose;
use App\Domain\Organization\Actions\InstallFirstAdministrator;
use App\Domain\Organization\Enums\PlatformPermission;
use App\Domain\Platform\Exceptions\AccessDenied;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * One-time installation of the platform (E3.8b): creates the first platform administrator. Takes no password —
 * the administrator sets one through the link sent to the address. Runs under SystemAuthority limited to the
 * installation purpose for this single operation; once the platform is installed it refuses (audited).
 */
#[Signature('platform:install-administrator {email : Adres e-mail pierwszego administratora} {--given-name= : Imię} {--family-name= : Nazwisko}')]
#[Description('Jednorazowo tworzy pierwszego administratora platformy (bez hasła domyślnego).')]
class InstallPlatformAdministrator extends Command
{
    public function handle(SystemAuthority $authority, InstallFirstAdministrator $install): int
    {
        $givenName = (string) ($this->option('given-name') ?? ($this->input->isInteractive() ? $this->ask(__('platform.install.given_name')) : ''));
        $familyName = (string) ($this->option('family-name') ?? ($this->input->isInteractive() ? $this->ask(__('platform.install.family_name')) : ''));
        $purpose = new SystemPurpose('platform.install', 'Instalacja platformy: utworzenie pierwszego administratora', [PlatformPermission::InstallFirstAdministrator], []);

        try {
            $administrator = $authority->run($purpose, fn () => $install->handle((string) $this->argument('email'), $givenName, $familyName));
        } catch (AccessDenied) {
            $this->error(__('platform.install.already_installed'));

            return self::FAILURE;
        } catch (ValidationException $e) {
            $this->error(__('platform.install.invalid'));
            $this->components->bulletList($e->validator->errors()->all());

            return self::FAILURE;
        }

        $this->info(__('platform.install.done', ['email' => $administrator->email]));
        $this->line(__('platform.install.next_steps'));

        return self::SUCCESS;
    }
}
