<?php

namespace App\Domain\Platform\Database;

use Illuminate\Support\Facades\DB;

/**
 * Blocks database-erasing Artisan commands (migrate:fresh, migrate:refresh, migrate:reset, migrate:rollback,
 * db:wipe) unless every guarded connection points to an unambiguous throw-away database:
 * - the environment is `local` or `testing`, and
 * - the database name ends with `_test`, or equals DB_ALLOW_DESTRUCTIVE_ON (explicit opt-in for one
 *   named local database, e.g. to rebuild the development database on purpose).
 * Production, staging and the local development database are protected by default.
 */
final class DestructiveCommandGuard
{
    private const SAFE_ENVIRONMENTS = ['local', 'testing'];

    public static function apply(): void
    {
        DB::prohibitDestructiveCommands(! self::allows(
            (string) app()->environment(),
            self::guardedDatabases(),
            config('database.allow_destructive_on'),
        ));
    }

    /** @param list<string> $databases */
    public static function allows(string $environment, array $databases, ?string $explicitlyAllowed): bool
    {
        if (! in_array($environment, self::SAFE_ENVIRONMENTS, true) || $databases === []) {
            return false;
        }
        foreach ($databases as $database) {
            $isTestDatabase = str_ends_with($database, '_test');
            $isOptedIn = $explicitlyAllowed !== null && $explicitlyAllowed !== '' && $database === $explicitlyAllowed;
            if (! $isTestDatabase && ! $isOptedIn) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function guardedDatabases(): array
    {
        $names = array_unique([config('database.default'), ...config('database.guarded_connections', [])]);

        return array_values(array_map(fn (string $name) => (string) config("database.connections.{$name}.database"), $names));
    }
}
