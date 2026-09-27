<?php

namespace App\Providers;

use App\Domain\Identity\Auth\AuditedUserProvider;
use App\Domain\Identity\Listeners\ResolvePersonOfVerifiedAccount;
use App\Domain\Organization\Access\AccessDecider;
use App\Domain\Organization\Access\SystemAuthority;
use App\Domain\Organization\Enums\Permission;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Database\DestructiveCommandGuard;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(SystemAuthority::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DestructiveCommandGuard::apply();

        // Z-021: length over composition rules (NIST SP 800-63B).
        Password::defaults(fn () => Password::min(12)->max(255));

        // Laravel Gate / policies ask the same decider: Gate::allows('members.manage', $organization).
        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            $permission = Permission::tryFrom($ability);
            $target = $arguments[0] ?? null;

            return $permission !== null && $target instanceof Organization
                ? app(AccessDecider::class)->decide($user, $permission, $target)->allowed
                : null;
        });

        Event::listen(Verified::class, ResolvePersonOfVerifiedAccount::class);

        Auth::provider('audited-eloquent', fn ($app, array $config) => new AuditedUserProvider($app['hash'], $config['model']));
    }
}
