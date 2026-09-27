<?php

namespace Tests\Feature\Domain\Platform;

use App\Domain\Platform\Database\DestructiveCommandGuard;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DestructiveCommandGuardTest extends TestCase
{
    /** @return array<string, array{0: string, 1: list<string>, 2: ?string, 3: bool}> */
    public static function cases(): array
    {
        return [
            'testy na bazie *_test' => ['testing', ['udzio_test', 'udzio_test'], null, true],
            'lokalnie na bazie *_test' => ['local', ['udzio_test'], null, true],
            'lokalna baza deweloperska bez zgody' => ['local', ['udzio'], null, false],
            'lokalna baza deweloperska z jawną zgodą' => ['local', ['udzio'], 'udzio', true],
            'zgoda na inną bazę' => ['local', ['udzio'], 'udzio_other', false],
            'pusta zgoda' => ['local', ['udzio'], '', false],
            'produkcja nawet z *_test' => ['production', ['udzio_test'], null, false],
            'produkcja z jawną zgodą' => ['production', ['udzio'], 'udzio', false],
            'staging' => ['staging', ['udzio_test'], null, false],
            'jedno z połączeń na prawdziwej bazie' => ['testing', ['udzio_test', 'udzio'], null, false],
        ];
    }

    #[DataProvider('cases')]
    public function test_decision(string $environment, array $databases, ?string $optIn, bool $allowed): void
    {
        $this->assertSame($allowed, DestructiveCommandGuard::allows($environment, $databases, $optIn));
    }

    public function test_erasing_commands_are_refused_when_the_guard_does_not_allow_them(): void
    {
        // Unreachable probe connection: even a broken guard could not erase anything real.
        config([
            'database.connections.guard_probe' => [...config('database.connections.mysql'), 'host' => '127.0.0.1', 'port' => 1, 'database' => 'udzio'],
            'database.guarded_connections' => ['mysql', 'audit', 'guard_probe'],
        ]);
        DestructiveCommandGuard::apply();

        foreach (['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'db:wipe'] as $command) {
            $this->assertSame(1, Artisan::call($command, ['--database' => 'guard_probe', '--force' => true]), $command);
            $this->assertStringContainsString('prohibited', Artisan::output(), $command);
        }
    }

    public function test_the_test_database_itself_is_allowed(): void
    {
        DestructiveCommandGuard::apply();

        $this->assertTrue(DestructiveCommandGuard::allows('testing', [(string) config('database.connections.mysql.database')], null));
    }
}
