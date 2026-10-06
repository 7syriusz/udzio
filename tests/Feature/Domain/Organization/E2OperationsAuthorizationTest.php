<?php

namespace Tests\Feature\Domain\Organization;

use App\Domain\Identity\Actions\AddContact;
use App\Domain\Identity\Actions\RegisterPerson;
use App\Domain\Identity\Enums\ContactChannel;
use App\Domain\Identity\Enums\PersonLinkReviewStatus;
use App\Domain\Identity\Enums\RepresentationMethod;
use App\Domain\Identity\Enums\RepresentationScope;
use App\Domain\Identity\Exceptions\RepresentationNotAllowed;
use App\Domain\Identity\Models\Person;
use App\Domain\Identity\Models\PersonLinkReview;
use App\Domain\Identity\Models\Representation;
use App\Domain\Organization\Access\PersonLinkReviewAccess;
use App\Domain\Organization\Access\SystemAuthority;
use App\Domain\Organization\Actions\AdmitMember;
use App\Domain\Organization\Actions\AssignRole;
use App\Domain\Organization\Actions\CreateAccessRole;
use App\Domain\Organization\Actions\EndMembership;
use App\Domain\Organization\Actions\EstablishRepresentation;
use App\Domain\Organization\Actions\MoveOrganization;
use App\Domain\Organization\Actions\ResolvePersonLinkReviewAsOperator;
use App\Domain\Organization\Enums\ScopeInheritance;
use App\Domain\Organization\Models\AccessRole;
use App\Domain\Organization\Models\Membership;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\PlatformRoleAssignment;
use App\Domain\Platform\Actor;
use App\Domain\Platform\ActorContext;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Domain\Platform\Models\AuditEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\AnyScopeTestPurpose;
use Tests\TestCase;

/**
 * E3.9 (Z-042): the E2 procedures have an owner in the role system — resolving an account-to-person review
 * and establishing a representation need the permission in a unit where the people concerned are members.
 */
class E2OperationsAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $company;

    private Organization $office;

    private Organization $foreign;

    private AccessRole $resolver;

    private AccessRole $establisher;

    private Person $maria;

    private Person $jan;

    private Person $ewa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00:00', 'UTC'));
        [$this->company, $this->office, $this->foreign] = Organization::factory()->count(3)->create()->all();
        $this->app->make(MoveOrganization::class)->handle($this->office, $this->company, 'Struktura');
        $this->system(function (): void {
            $this->resolver = $this->app->make(CreateAccessRole::class)->handle($this->company, 'Weryfikacja kont', ['person_links.resolve', 'members.view'], 'Rola');
            $this->establisher = $this->app->make(CreateAccessRole::class)->handle($this->company, 'Opieka', ['representations.establish'], 'Rola');
        });
        // Maria is a member of the office, Jan of a foreign organization, Ewa of no organization.
        $this->maria = $this->personWithEmail('rodzina@example.test', 'Maria', '2014-05-01');
        $this->jan = $this->personWithEmail('rodzina@example.test', 'Jan');
        $this->ewa = $this->personWithEmail('ewa@example.test', 'Ewa');
        $this->app->make(AdmitMember::class)->handle($this->maria, $this->office, 'member', 'Przyjęcie');
        $this->app->make(AdmitMember::class)->handle($this->jan, $this->foreign, 'member', 'Przyjęcie');
        $this->travel(1)->minute();
    }

    private function system(Closure $operation): mixed
    {
        return $this->app->make(SystemAuthority::class)->run(new AnyScopeTestPurpose, $operation);
    }

    private function personWithEmail(string $email, string $given, ?string $birthDate = null): Person
    {
        $person = $this->app->make(RegisterPerson::class)->handle(['given_name' => $given, 'family_name' => 'Nowak', 'birth_date' => $birthDate]);
        $contact = $this->app->make(AddContact::class)->handle($person, ContactChannel::Email, $email);
        $this->app->make(AuditReason::class)->because('verified in test', fn () => $contact->update(['verified_at' => now()]));

        return $person;
    }

    /** An account whose e-mail matches several people opens a review (Z-022). */
    private function review(string $email = 'rodzina@example.test'): PersonLinkReview
    {
        $account = User::factory()->unverified()->create(['email' => $email, 'given_name' => 'Nowe', 'family_name' => 'Konto']);
        $this->actingAs($account)->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $account->id, 'hash' => sha1($account->email)]));
        $this->app['auth']->guard('web')->logout();

        return PersonLinkReview::query()->where('user_id', $account->id)->sole();
    }

    private function operator(AccessRole $role, Organization $scope, bool $mfa = true): User
    {
        $operator = $mfa ? User::factory()->withTwoFactor()->create() : User::factory()->create();
        $this->system(fn () => $this->app->make(AssignRole::class)->handle($operator, $role, $scope, ScopeInheritance::UnitAndDescendants, null, 'Nadanie'));
        $this->travel(1)->second();

        return $operator;
    }

    private function platformResolver(): User
    {
        $administrator = User::factory()->withTwoFactor()->create();
        PlatformRoleAssignment::startPeriod(['user_id' => $administrator->id, 'role' => 'administrator'], now()->subMinute());

        return $administrator;
    }

    private function as(User $account, Closure $operation): mixed
    {
        return $this->app->make(ActorContext::class)->runAs(Actor::account((string) $account->id), $operation);
    }

    private function resolveAs(User $operator, PersonLinkReview $review, ?Person $person, string $basis = 'document', string $evidence = 'Dowód osobisty sprawdzony w biurze'): PersonLinkReview
    {
        return $this->as($operator, fn () => $this->app->make(ResolvePersonLinkReviewAsOperator::class)->handle($review, $person, $basis, $evidence, 'Wniosek właściciela konta'));
    }

    /** A guardian policy of the scenario: a minor member, a checked guardianship document, at most a year. */
    private function guardianPolicy(array $overrides = []): void
    {
        config(['organization.representation_policies.guardian_of_minor' => [
            'method' => 'document',
            'document' => 'dokument potwierdzający opiekę',
            'represented' => ['functions' => ['member'], 'max_age' => 17],
            'scopes' => ['profile.view', 'registrations.manage', 'consents.manage'],
            'max_days' => 365,
            'organizations' => null,
            ...$overrides,
        ]]);
    }

    private function establishAs(User $operator, Person $represented, ?Person $representative = null, string $policy = 'guardian_of_minor', array $scopes = [RepresentationScope::ProfileView], ?DateTimeInterface $until = null, string $document = 'Postanowienie sądu III RC 12/26'): Representation
    {
        return $this->as($operator, fn () => $this->app->make(EstablishRepresentation::class)->handle(
            $policy, $representative ?? $this->ewa, $represented, $scopes, $document, now(), $until ?? now()->addDays(200), 'Opieka nad członkiem'));
    }

    private function assertDenied(Closure $attempt, string $reason, int $status = 403): void
    {
        try {
            $attempt();
            $this->fail("Oczekiwano odmowy: {$reason}");
        } catch (AccessDenied $denied) {
            $this->assertSame($status, $denied->status() ?? 403);
            $this->assertSame($reason, AuditEntry::on('audit')->where('action', 'access.denied')->latest('id')->first()->after_values['reason']);
        }
    }

    public function test_operator_links_the_account_to_a_candidate_in_scope(): void
    {
        $review = $this->review();
        $operator = $this->operator($this->resolver, $this->company);

        $this->resolveAs($operator, $review, $this->maria);

        $this->assertSame(PersonLinkReviewStatus::Resolved, $review->fresh()->status);
        $this->assertSame($this->maria->id, $review->account->fresh()->person_id);
        $granted = AuditEntry::query()->where(['action' => 'access.granted', 'subject_type' => 'person_link_review', 'subject_id' => $review->public_id])->sole();
        $this->assertSame($this->office->public_id, $granted->organization_id);
    }

    public function test_operator_cannot_pick_a_candidate_outside_scope_nor_decide_on_a_new_person(): void
    {
        $review = $this->review();
        $operator = $this->operator($this->resolver, $this->company);

        $this->assertDenied(fn () => $this->resolveAs($operator, $review, $this->jan), 'candidate_outside_scope');
        $this->assertDenied(fn () => $this->resolveAs($operator, $review, null), 'candidates_outside_scope');
        $this->assertSame(PersonLinkReviewStatus::Open, $review->fresh()->status);
        $this->assertNull($review->account->fresh()->person_id);
    }

    public function test_operator_covering_every_candidate_may_choose_a_new_person(): void
    {
        $this->app->make(AdmitMember::class)->handle($this->jan, $this->company, 'member', 'Przyjęcie');
        $this->travel(1)->second();
        $review = $this->review();
        $operator = $this->operator($this->resolver, $this->company);

        $this->resolveAs($operator, $review, null);

        $this->assertNotContains($review->account->fresh()->person_id, [$this->maria->id, $this->jan->id]);
    }

    public function test_operator_without_any_candidate_in_scope_gets_not_found(): void
    {
        $review = $this->review();
        $foreignRole = $this->system(fn () => $this->app->make(CreateAccessRole::class)->handle($this->foreign, 'Weryfikacja', ['person_links.resolve'], 'Rola'));
        $other = $this->operator($foreignRole, $this->foreign);
        $officeOnly = $this->operator($this->establisher, $this->company);

        $this->assertDenied(fn () => $this->resolveAs($officeOnly, $review, $this->maria), 'no_candidate_in_scope', 404);
        $this->assertDenied(fn () => $this->resolveAs($other, $review, $this->maria), 'candidate_outside_scope');
        $this->assertSame(PersonLinkReviewStatus::Open, $review->fresh()->status);
    }

    public function test_resolving_needs_mfa_and_never_concerns_ones_own_account(): void
    {
        $review = $this->review();
        $withoutMfa = $this->operator($this->resolver, $this->company, mfa: false);

        try {
            $this->resolveAs($withoutMfa, $review, $this->maria);
            $this->fail('Oczekiwano odmowy bez MFA.');
        } catch (AccessDenied $denied) {
            $this->assertSame('access.mfa_required', $denied->getMessage());
        }

        $holder = $review->account;
        $this->app->make(AuditReason::class)->because('test: MFA', fn () => $holder->forceFill(User::factory()->withTwoFactor()->make()->only(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']))->save());
        $this->system(fn () => $this->app->make(AssignRole::class)->handle($holder, $this->resolver, $this->company, ScopeInheritance::UnitAndDescendants, null, 'Nadanie'));
        $this->travel(1)->second();
        $this->assertDenied(fn () => $this->resolveAs($holder->fresh(), $review, $this->maria), 'own_review');
        $this->assertSame(PersonLinkReviewStatus::Open, $review->fresh()->status);
    }

    public function test_platform_administrator_resolves_any_review_but_not_their_own(): void
    {
        $this->personWithEmail('ewa@example.test', 'Ewelina');
        $review = $this->review('ewa@example.test');
        $second = $this->review('rodzina@example.test');
        $administrator = $this->platformResolver();
        $operator = $this->operator($this->resolver, $this->company);

        $this->assertDenied(fn () => $this->resolveAs($operator, $second, $this->jan), 'candidate_outside_scope');
        $this->resolveAs($administrator, $second, null);
        $this->assertNotNull($second->account->fresh()->person_id);
        $this->assertSame(1, AuditEntry::query()->where(['action' => 'access.granted', 'subject_type' => 'account', 'subject_id' => (string) $second->user_id])->count());

        $own = $review->account;
        $this->app->make(AuditReason::class)->because('test: MFA', fn () => $own->forceFill(User::factory()->withTwoFactor()->make()->only(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']))->save());
        PlatformRoleAssignment::startPeriod(['user_id' => $own->id, 'role' => 'administrator'], now()->subMinute());
        $this->assertDenied(fn () => $this->resolveAs($own->fresh(), $review, $this->ewa), 'own_account');
    }

    public function test_operator_sees_only_covered_candidates_with_minimal_data(): void
    {
        $review = $this->review();
        $operator = $this->operator($this->resolver, $this->company);
        $access = $this->app->make(PersonLinkReviewAccess::class);

        $view = $access->candidatesFor($operator, $review);
        $this->assertSame([['public_id' => $this->maria->public_id, 'given_name' => 'Maria', 'family_name' => 'Nowak']], $view['candidates']->all());
        $this->assertFalse($view['covers_all']);
        $this->assertSame([$review->id], $access->openReviewsFor($operator)->modelKeys());

        $administratorView = $access->candidatesFor($this->platformResolver(), $review);
        $this->assertCount(2, $administratorView['candidates']);
        $this->assertTrue($administratorView['covers_all']);

        $stranger = $this->operator($this->establisher, $this->company);
        $this->assertSame([], $access->openReviewsFor($stranger)->all());
        $this->assertSame([], $access->candidatesFor($stranger, $review)['candidates']->all());
    }

    public function test_role_establishes_a_representation_under_the_scenario_policy(): void
    {
        $this->guardianPolicy();
        $operator = $this->operator($this->establisher, $this->company);

        $representation = $this->establishAs($operator, $this->maria, until: now()->addDays(200));

        $this->assertSame([$this->ewa->id, $this->maria->id], [$representation->representative_person_id, $representation->represented_person_id]);
        $this->assertSame(RepresentationMethod::Document, $representation->method);
        $this->assertSame('Postanowienie sądu III RC 12/26', $representation->basis);
        $this->assertSame((string) $operator->id, $representation->established_by_id);
        $this->assertTrue($representation->fresh()->valid_to->equalTo(now()->addDays(200)));
        $this->assertSame($this->office->public_id, AuditEntry::query()->where(['action' => 'access.granted', 'subject_type' => 'person', 'subject_id' => $this->maria->public_id])->sole()->organization_id);
        $entry = AuditEntry::query()->where('action', 'representation.established_by_role')->sole();
        $this->assertSame(['guardian_of_minor', 'Postanowienie sądu III RC 12/26'], [$entry->after_values['policy'], $entry->after_values['checked_document']]);
        $this->assertSame('Opieka nad członkiem', $entry->reason);
    }

    public function test_without_an_allowed_basis_a_role_establishes_nothing(): void
    {
        $operator = $this->operator($this->establisher, $this->company);
        $attempts = [
            'brak polityki w konfiguracji' => fn () => $this->establishAs($operator, $this->maria),
            'zakres spoza polityki' => function () use ($operator) {
                $this->guardianPolicy();
                $this->establishAs($operator, $this->maria, scopes: [RepresentationScope::PaymentsManage]);
            },
            'bez sprawdzonego dokumentu' => fn () => $this->establishAs($operator, $this->maria, document: '  '),
            'okres dłuższy niż pozwala polityka' => fn () => $this->establishAs($operator, $this->maria, until: now()->addDays(400)),
            'osoba pełnoletnia' => function () use ($operator) {
                $this->app->make(AdmitMember::class)->handle($this->ewa, $this->office, 'member', 'Przyjęcie');
                $this->establishAs($operator, $this->ewa, representative: $this->jan);
            },
            'funkcja spoza polityki' => function () use ($operator) {
                $this->guardianPolicy(['represented' => ['functions' => ['trainer'], 'max_age' => null]]);
                $this->establishAs($operator, $this->maria);
            },
        ];
        foreach ($attempts as $case => $attempt) {
            try {
                $attempt();
                $this->fail("Reprezentacja nie powinna powstać: {$case}");
            } catch (ValidationException|AccessDenied) {
            }
        }
        $this->assertSame(0, Representation::query()->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'representation.established_by_role')->count());
    }

    public function test_representation_outside_scope_or_without_permission_is_not_found(): void
    {
        $this->guardianPolicy(['represented' => ['functions' => null, 'max_age' => null]]);
        $operator = $this->operator($this->establisher, $this->company);
        $resolverOnly = $this->operator($this->resolver, $this->company);

        $this->assertDenied(fn () => $this->establishAs($operator, $this->jan), 'represented_outside_scope', 404);
        $this->assertDenied(fn () => $this->establishAs($operator, $this->ewa, representative: $this->maria), 'represented_outside_scope', 404);
        $this->assertDenied(fn () => $this->establishAs($resolverOnly, $this->maria), 'represented_outside_scope', 404);
        $this->assertSame(0, Representation::query()->count());
    }

    public function test_representation_by_role_needs_mfa_and_never_for_oneself(): void
    {
        $this->guardianPolicy();
        $withoutMfa = $this->operator($this->establisher, $this->company, mfa: false);
        try {
            $this->establishAs($withoutMfa, $this->maria);
            $this->fail('Oczekiwano odmowy bez MFA.');
        } catch (AccessDenied $denied) {
            $this->assertSame('access.mfa_required', $denied->getMessage());
        }

        $operator = $this->operator($this->establisher, $this->company);
        $this->app->make(AuditReason::class)->because('test: link', fn () => $operator->forceFill(['person_id' => $this->ewa->id])->save());
        $this->expectException(RepresentationNotAllowed::class);
        $this->establishAs($operator->fresh(), $this->maria);
    }

    public function test_former_member_is_resolved_by_an_operator_with_todays_history_rights(): void
    {
        $this->app->make(EndMembership::class)->handle(Membership::query()->where('person_id', $this->maria->id)->sole(), 'Koniec zajęć');
        $this->travel(1)->minute();
        $review = $this->review();
        $withoutHistory = $this->operator($this->resolver, $this->company);
        $historian = $this->system(fn () => $this->app->make(CreateAccessRole::class)->handle($this->company, 'Weryfikacja z historią', ['person_links.resolve', 'members.history.view'], 'Rola'));
        $withHistory = $this->operator($historian, $this->company);

        $this->assertDenied(fn () => $this->resolveAs($withoutHistory, $review, $this->maria), 'no_candidate_in_scope', 404);
        $this->assertSame([], $this->app->make(PersonLinkReviewAccess::class)->candidatesFor($withoutHistory, $review)['candidates']->all());

        $this->resolveAs($withHistory, $review, $this->maria);
        $this->assertSame($this->maria->id, $review->account->fresh()->person_id);
        $this->assertSame('organization', AuditEntry::query()->where('action', 'person_link_review.resolved')->sole()->after_values['level']);
    }

    public function test_review_stays_open_without_a_reliable_ground(): void
    {
        $review = $this->review();
        $operator = $this->operator($this->resolver, $this->company);
        $administrator = $this->platformResolver();

        foreach ([
            [$operator, 'name_similarity', 'Te same imię i nazwisko'],
            [$operator, 'similar_contact', 'Podobny adres e-mail'],
            [$operator, 'document', ' '],
            [$administrator, '', 'Brak'],
            [$administrator, 'name_similarity', 'Imię i nazwisko się zgadzają'],
        ] as [$actor, $basis, $evidence]) {
            try {
                $this->resolveAs($actor, $review, $this->maria, $basis, $evidence);
                $this->fail("Bez wiarygodnej podstawy: {$basis}");
            } catch (ValidationException) {
            }
        }
        $this->assertSame(PersonLinkReviewStatus::Open, $review->fresh()->status);
        $this->assertNull($review->account->fresh()->person_id);
        $this->assertSame(0, AuditEntry::query()->where('action', 'person_link_review.resolved')->count());
    }

    public function test_out_of_scope_candidates_stay_hidden_and_the_operator_is_told_to_escalate(): void
    {
        $review = $this->review();
        $operator = $this->operator($this->resolver, $this->company);

        $people = Person::query()->count();
        $view = $this->app->make(PersonLinkReviewAccess::class)->candidatesFor($operator, $review);
        $this->assertSame('Tej sprawy nie można rozstrzygnąć w Twoim zakresie — wymaga rozstrzygnięcia na wyższym poziomie.', $view['escalation_notice']);
        $this->assertStringNotContainsString('Jan', json_encode($view, JSON_UNESCAPED_UNICODE));
        try {
            $this->resolveAs($operator, $review, null);
            $this->fail('Nowa osoba przy kandydacie spoza zakresu.');
        } catch (AccessDenied $denied) {
            $this->assertSame('access.escalation_required', $denied->getMessage());
        }
        $this->assertSame($people, Person::query()->count(), 'Nie powstała dodatkowa PERSON.');
    }
}
