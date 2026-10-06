<?php

namespace Tests\Feature\Domain\Organization;

use App\Domain\Organization\Models\PlatformInstallation;
use App\Domain\Organization\Models\PlatformRoleAssignment;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Concurrency\Race;
use Tests\Support\Concurrency\Scenarios\InstallPlatform;
use Tests\TestCase;

#[Group('concurrency')]
class PlatformInstallationConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        // Committed rows from the workers must not reach tests that run in transactions.
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_two_parallel_installations_create_exactly_one_administrator(): void
    {
        // The gate holds the installation record both workers try to write, then withdraws it.
        $race = Race::run(
            "INSERT INTO platform_installations (id, installed_at, installed_by, created_at, updated_at) VALUES (1, NOW(6), 'gate', NOW(6), NOW(6))",
            [],
            [[InstallPlatform::class, ['email' => 'pierwszy@example.test']], [InstallPlatform::class, ['email' => 'drugi@example.test']]],
            releaseByRollback: true,
        );

        $results = json_encode($race['results'], JSON_UNESCAPED_UNICODE);
        $this->assertSame(2, $race['blocked'], 'Oba procesy musiały czekać na rekord instalacji: '.$results);
        $codes = array_map(fn (array $r) => $r['result'] ?? $r['error'], $race['results']);
        sort($codes);
        $this->assertSame([0, 1], $codes, $results);

        $this->assertSame(1, User::query()->count(), 'Przegrana instalacja nie zostawia konta.');
        $this->assertSame(1, PlatformRoleAssignment::query()->count());
        $this->assertSame(User::query()->sole()->id, PlatformInstallation::query()->sole()->administrator_user_id);
        $this->assertSame(1, AuditEntry::query()->where('action', 'platform.installed')->count());
        $this->assertSame('installation_completed', AuditEntry::on('audit')->where('action', 'access.denied')->sole()->after_values['reason']);
    }
}
