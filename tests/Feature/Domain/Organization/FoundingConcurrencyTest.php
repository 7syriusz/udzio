<?php

namespace Tests\Feature\Domain\Organization;

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\RoleAssignment;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Concurrency\Race;
use Tests\Support\Concurrency\Scenarios\FoundOrganization;
use Tests\TestCase;

#[Group('concurrency')]
class FoundingConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_the_same_form_submitted_twice_in_parallel_founds_one_organization(): void
    {
        $founder = User::factory()->withTwoFactor()->create();
        $submission = [FoundOrganization::class, ['account' => $founder->id, 'name' => 'Fundacja Zielona', 'key' => '01J9ZFORMSUBMISSIONKEY0001']];

        // The gate holds the founder's row: the first worker waits on it, the second on the request key.
        $race = Race::run('SELECT id FROM users WHERE id = ? FOR UPDATE', [$founder->id], [$submission, $submission]);

        $results = json_encode($race['results'], JSON_UNESCAPED_UNICODE);
        $this->assertSame(2, $race['blocked'], 'Oba procesy musiały czekać: '.$results);
        $this->assertTrue($race['results'][0]['ok'] && $race['results'][1]['ok'], $results);
        $this->assertSame($race['results'][0]['result'], $race['results'][1]['result'], 'Obie odpowiedzi wskazują tę samą organizację.');
        $this->assertSame(1, Organization::query()->count());
        $this->assertSame(1, RoleAssignment::query()->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'organization.founded')->count());
    }
}
