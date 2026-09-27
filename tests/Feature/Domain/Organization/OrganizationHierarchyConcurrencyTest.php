<?php

namespace Tests\Feature\Domain\Organization;

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationParent;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Concurrency\Race;
use Tests\Support\Concurrency\Scenarios\MoveOrganization;
use Tests\TestCase;

#[Group('concurrency')]
class OrganizationHierarchyConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_opposite_parallel_moves_cannot_create_a_cycle(): void
    {
        [$a, $b] = Organization::factory()->count(2)->create()->all();

        $race = Race::run('SELECT id FROM organizations WHERE id = ? FOR UPDATE', [$a->id], [
            [MoveOrganization::class, ['child' => $a->id, 'parent' => $b->id]],
            [MoveOrganization::class, ['child' => $b->id, 'parent' => $a->id]],
        ]);

        $this->assertSame(2, $race['blocked']);
        $outcomes = array_map(fn (array $r) => $r['ok'] ? 'ok' : $r['error'], $race['results']);
        sort($outcomes);
        $this->assertSame([ValidationException::class, 'ok'], $outcomes, json_encode($race['results']));
        $this->assertSame(1, OrganizationParent::query()->whereNull('valid_to')->count());
    }
}
