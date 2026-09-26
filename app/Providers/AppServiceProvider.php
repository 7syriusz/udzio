<?php

namespace App\Providers;

use App\Domain\Identity\Auth\AuditedUserProvider;
use Illuminate\Support\Facades\Auth;
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
        // Z-021: length over composition rules (NIST SP 800-63B).
        Password::defaults(fn () => Password::min(12)->max(255));

        Auth::provider('audited-eloquent', fn ($app, array $config) => new AuditedUserProvider($app['hash'], $config['model']));
    }
}
