<?php

namespace Tests\Feature\Domain\Identity;

use App\Domain\Identity\Actions\ActOnBehalf;
use App\Domain\Identity\Actions\ChangeRepresentationScopes;
use App\Domain\Identity\Actions\EndRepresentation;
use App\Domain\Identity\Actions\GrantRepresentation;
use App\Domain\Identity\Actions\RegisterPerson;
use App\Domain\Identity\Actions\UpdatePersonDetails;
use App\Domain\Identity\Enums\RepresentationMethod;
use App\Domain\Identity\Enums\RepresentationScope as Scope;
use App\Domain\Identity\Exceptions\RepresentationNotAllowed;
use App\Domain\Identity\Models\Person;
use App\Domain\Identity\Models\Representation;
use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\TestCase;

class RepresentationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Person $parent;

    private Person $child;

    private User $parentAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parent = $this->person('Maria');
        $this->child = $this->person('Zosia');
        $this->parentAccount = User::factory()->create(['person_id' => $this->parent->id]);
    }

    private function person(string $given): Person
    {
        return $this->app->make(RegisterPerson::class)->handle(['given_name' => $given, 'family_name' => 'Nowak']);
    }

    /** @param list<Scope> $scopes */
    private function grant(array $scopes, string $from = '-1 day'): Representation
    {
        return $this->app->make(GrantRepresentation::class)->handle($this->parent, $this->child, $scopes, RepresentationMethod::Document, 'birth certificate no. AB-123 checked', now()->modify($from), 'birth certificate checked by operator');
    }

    private function correctChildName(string $familyName): void
    {
        $this->app->make(ActorContext::class)->runAs(Actor::account((string) $this->parentAccount->id), fn () => $this->app->make(ActOnBehalf::class)->handle($this->parentAccount, $this->child, Scope::ProfileUpdate,
            fn () => $this->app->make(UpdatePersonDetails::class)->handle($this->child, ['family_name' => $familyName], 'guardian corrected child name')));
    }

    public function test_guardian_acts_for_the_child_and_the_audit_keeps_both_roles(): void
    {
        $representation = $this->grant([Scope::ProfileView, Scope::ProfileUpdate]);

        $this->correctChildName('Kowalska');

        $this->assertSame('Kowalska', $this->child->fresh()->family_name);
        $update = AuditEntry::query()->where('action', 'person.updated')->sole();
        $this->assertSame(['account', (string) $this->parentAccount->id], [$update->actor_type->value, $update->actor_id], 'ACTOR: konto rodzica');
        $this->assertSame(['person', (string) $this->child->id], [$update->subject_type, $update->subject_id], 'SUBJECT: dziecko');
        $onBehalf = AuditEntry::query()->where('action', 'person.acted_on_behalf')->sole();
        $this->assertSame((string) $this->child->id, $onBehalf->subject_id);
        $this->assertEqualsCanonicalizing(['representation' => $representation->public_id, 'representative_person_id' => $this->parent->id, 'scope' => 'profile.update'], $onBehalf->after_values);
        $this->assertSame($update->actor_id, $onBehalf->actor_id);
    }

    public function test_representation_alone_is_not_full_access(): void
    {
        $this->grant([Scope::ProfileView]);

        $this->assertTrue($this->app->make(ActOnBehalf::class)->allows($this->parentAccount, $this->child, Scope::ProfileView));
        $this->expectException(AccessDenied::class);
        $this->correctChildName('Kowalska');
    }

    public function test_refusal_over_http_is_audited_with_the_represented_person_as_subject(): void
    {
        $this->grant([Scope::ProfileView]);
        Route::middleware('web')->post('/_probe/child/{person}', function (Person $person) {
            app(ActOnBehalf::class)->handle(request()->user(), $person, Scope::ProfileUpdate, fn () => null);
        })->name('probe.child');

        $this->actingAs($this->parentAccount)->post('/_probe/child/'.$this->child->public_id)->assertForbidden();

        $denial = AuditEntry::on('audit')->where('action', 'access.denied')->sole();
        $this->assertSame(['person', $this->child->public_id], [$denial->subject_type, $denial->subject_id]);
        $this->assertSame('representation.profile.update', $denial->after_values['ability']);
        $this->assertSame('Zosia', $this->child->fresh()->given_name);
    }

    public function test_end_of_representation_takes_access_away(): void
    {
        $representation = $this->grant([Scope::ProfileUpdate]);
        $this->app->make(EndRepresentation::class)->handle($representation, now(), 'child came of age');

        $this->travel(1)->seconds();
        $this->assertFalse($this->app->make(ActOnBehalf::class)->allows($this->parentAccount, $this->child, Scope::ProfileUpdate));
        $this->assertCount(1, $representation->history(), 'Zakończona reprezentacja zostaje w historii.');
    }

    public function test_representation_starting_in_the_future_is_not_active_yet(): void
    {
        $this->grant([Scope::ProfileUpdate], from: '+1 day');

        $this->assertFalse($this->app->make(ActOnBehalf::class)->allows($this->parentAccount, $this->child, Scope::ProfileUpdate));
        $this->travel(2)->days();
        $this->assertTrue($this->app->make(ActOnBehalf::class)->allows($this->parentAccount, $this->child, Scope::ProfileUpdate));
    }

    public function test_scope_change_is_a_new_period_with_history(): void
    {
        $representation = $this->grant([Scope::ProfileView]);

        $this->app->make(ChangeRepresentationScopes::class)->handle($representation, [Scope::ProfileUpdate, Scope::ProfileView], RepresentationMethod::PartiesAcceptance, 'acceptance recorded 2026-09-27', now(), 'guardian asked for edit rights');
        $this->travel(1)->seconds();

        $this->assertSame([['profile.view'], ['profile.update', 'profile.view']], $representation->history()->pluck('scopes')->all());
        $this->assertTrue($this->app->make(ActOnBehalf::class)->allows($this->parentAccount, $this->child, Scope::ProfileUpdate));
    }

    public function test_direction_matters_and_accounts_without_person_have_no_representation(): void
    {
        $this->grant([Scope::ProfileUpdate]);
        $childAccount = User::factory()->create(['person_id' => $this->child->id]);
        $unlinked = User::factory()->create();

        $acting = $this->app->make(ActOnBehalf::class);
        $this->assertFalse($acting->allows($childAccount, $this->parent, Scope::ProfileUpdate), 'Reprezentacja działa w jedną stronę.');
        $this->assertFalse($acting->allows($unlinked, $this->child, Scope::ProfileUpdate));
        $this->assertTrue($acting->allows($childAccount, $this->child, Scope::ProfileUpdate), 'Osoba działa za siebie bez reprezentacji.');
    }

    public function test_invalid_grants_are_refused(): void
    {
        $grant = $this->app->make(GrantRepresentation::class);
        foreach ([[$this->parent, [Scope::ProfileView]], [$this->child, []]] as [$represented, $scopes]) {
            try {
                $grant->handle($this->parent, $represented, $scopes, RepresentationMethod::Document, 'birth certificate no. AB-123 checked', now(), 'test');
                $this->fail('Grant must be refused.');
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(0, Representation::query()->count());
    }

    public function test_each_period_records_method_basis_and_establishing_actor(): void
    {
        $operator = User::factory()->create(['person_id' => $this->person('Operator')->id]);

        $representation = $this->app->make(ActorContext::class)->runAs(Actor::account((string) $operator->id), fn () => $this->grant([Scope::ProfileView]));

        $this->assertSame(RepresentationMethod::Document, $representation->method);
        $this->assertSame('birth certificate no. AB-123 checked', $representation->basis);
        $this->assertSame(['account', (string) $operator->id], [$representation->established_by_type, $representation->established_by_id]);
        $this->assertSame('[REDACTED]', AuditEntry::query()->where('action', 'representation.created')->sole()->after_values['basis'], 'Podstawa to dane RESTRICTED.');
    }

    public function test_nobody_establishes_or_extends_a_representation_for_themselves(): void
    {
        $asParent = fn (callable $operation) => $this->app->make(ActorContext::class)->runAs(Actor::account((string) $this->parentAccount->id), $operation);

        try {
            $asParent(fn () => $this->grant([Scope::ProfileView]));
            $this->fail('Self-established representation must be refused.');
        } catch (RepresentationNotAllowed) {
        }

        $representation = $this->grant([Scope::ProfileView]);
        $this->expectException(RepresentationNotAllowed::class);
        $asParent(fn () => $this->app->make(ChangeRepresentationScopes::class)->handle($representation, [Scope::ProfileView, Scope::PaymentsManage], RepresentationMethod::Declaration, 'I declare', now(), 'self extension'));
    }

    public function test_configuration_limits_methods_and_grantable_scope(): void
    {
        config(['identity.representation.methods' => ['role_decision'], 'identity.representation.grantable_scopes' => ['profile.view']]);
        $grant = $this->app->make(GrantRepresentation::class);

        foreach ([
            fn () => $grant->handle($this->parent, $this->child, [Scope::ProfileView], RepresentationMethod::Declaration, 'statement', now(), 'disabled method'),
            fn () => $grant->handle($this->parent, $this->child, [Scope::ProfileView, Scope::PaymentsManage], RepresentationMethod::RoleDecision, 'decision 7/2026', now(), 'scope outside'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Grant outside the configuration must be refused.');
            } catch (RepresentationNotAllowed) {
            }
        }

        $allowed = $grant->handle($this->parent, $this->child, [Scope::ProfileView], RepresentationMethod::RoleDecision, 'decision 7/2026', now()->subSecond(), 'within configuration');
        $this->assertSame(['profile.view'], $allowed->scopes);
    }

    public function test_scope_change_period_has_its_own_method_and_basis(): void
    {
        $representation = $this->grant([Scope::ProfileView]);

        $this->app->make(ChangeRepresentationScopes::class)->handle($representation, [Scope::ProfileView, Scope::ProfileUpdate], RepresentationMethod::PartiesAcceptance, 'acceptance no. 42', now(), 'extended by agreement');

        $history = $representation->history();
        $this->assertSame([RepresentationMethod::Document, RepresentationMethod::PartiesAcceptance], $history->pluck('method')->all());
        $this->assertSame(['birth certificate no. AB-123 checked', 'acceptance no. 42'], $history->pluck('basis')->all());
    }

    public function test_basis_is_required(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->app->make(GrantRepresentation::class)->handle($this->parent, $this->child, [Scope::ProfileView], RepresentationMethod::Document, '   ', now(), 'no basis');
    }
}
