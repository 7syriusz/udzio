<?php

namespace Tests\Feature\Domain\Organization;

use App\Domain\Identity\Actions\RegisterPerson;
use App\Domain\Organization\Actions\AdmitMember as Admit;
use App\Domain\Organization\Models\Membership;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Exceptions\ValidityConflict;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Concurrency\Race;
use Tests\Support\Concurrency\Scenarios\AdmitMember;
use Tests\Support\Concurrency\Scenarios\TransferMembership;
use Tests\TestCase;

#[Group('concurrency')]
class MembershipConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    /** Committed rows must not leak into transaction-based tests that run afterwards. */
    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    /** @param array{blocked: int, results: array<int, array<string, mixed>>} $race */
    private function assertOneWinner(array $race): void
    {
        $results = json_encode($race['results'], JSON_UNESCAPED_UNICODE);
        $this->assertSame(2, $race['blocked'], 'Oba procesy musiały czekać na blokadę osoby: '.$results);
        $outcomes = array_map(fn (array $r) => $r['ok'] ? 'ok' : $r['error'], $race['results']);
        sort($outcomes);
        $this->assertSame([ValidityConflict::class, 'ok'], $outcomes, $results);
    }

    public function test_parallel_admissions_of_one_person_open_exactly_one_membership(): void
    {
        $person = $this->app->make(RegisterPerson::class)->handle(['given_name' => 'Anna', 'family_name' => 'Nowak']);
        $organization = Organization::factory()->create();
        $worker = [AdmitMember::class, ['person' => $person->id, 'organization' => $organization->id]];

        $this->assertOneWinner(Race::run('SELECT id FROM people WHERE id = ? FOR UPDATE', [$person->id], [$worker, $worker]));

        $this->assertSame(1, Membership::query()->whereNull('valid_to')->count());
    }

    public function test_parallel_transfers_of_one_membership_leave_it_in_exactly_one_unit(): void
    {
        $person = $this->app->make(RegisterPerson::class)->handle(['given_name' => 'Anna', 'family_name' => 'Nowak']);
        [$origin, $first, $second] = Organization::factory()->count(3)->create()->all();
        $membership = $this->app->make(Admit::class)->handle($person, $origin, 'member', 'Przyjęcie');
        $this->travel(1)->second();

        $this->assertOneWinner(Race::run('SELECT id FROM people WHERE id = ? FOR UPDATE', [$person->id], [
            [TransferMembership::class, ['membership' => $membership->id, 'destination' => $first->id]],
            [TransferMembership::class, ['membership' => $membership->id, 'destination' => $second->id]],
        ]));

        $open = Membership::query()->whereNull('valid_to')->get();
        $this->assertCount(1, $open);
        $this->assertContains($open->sole()->organization_id, [$first->id, $second->id]);
        $this->assertSame($membership->id, $open->sole()->transferred_from_id);
    }
}
