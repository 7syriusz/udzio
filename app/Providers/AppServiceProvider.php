<?php

namespace App\Providers;

use App\Domain\Identity\Auth\AuditedUserProvider;
use App\Domain\Identity\Listeners\ResolvePersonOfVerifiedAccount;
use App\Domain\Platform\Database\DestructiveCommandGuard;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DestructiveCommandGuard::apply();

        // Z-021: length over composition rules (NIST SP 800-63B).
        Password::defaults(fn () => Password::min(12)->max(255));

        Event::listen(Verified::class, ResolvePersonOfVerifiedAccount::class);

        Auth::provider('audited-eloquent', fn ($app, array $config) => new AuditedUserProvider($app['hash'], $config['model']));
    }
}
