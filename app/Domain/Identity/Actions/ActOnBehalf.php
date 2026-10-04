<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\RepresentationScope;
use App\Domain\Identity\Models\Person;
use App\Domain\Identity\Models\Representation;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\Enums\DataClass;
use App\Domain\Platform\Exceptions\AccessDenied;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Runs an operation of an account (ACTOR) that concerns another PERSON (SUBJECT), A5 §1.4 / A5-03.
 * Allowed when the account's own person is the subject, or holds an active representation with the scope
 * at this moment. Otherwise AccessDenied (audited). The representation used is audited together with the
 * operation, so both roles can be reconstructed.
 */
final class ActOnBehalf
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function handle(User $account, Person $subject, RepresentationScope $scope, Closure $operation): mixed
    {
        return DB::transaction(function () use ($account, $subject, $scope, $operation) {
            if ($account->person_id !== null && $account->person_id === $subject->id) {
                return $operation();
            }
            $representation = $this->activeRepresentation($account, $subject);
            if ($representation === null || ! $representation->allows($scope)) {
                throw new AccessDenied('person', $subject->public_id, null, 'representation.'.$scope->value);
            }

            $this->audit->handle('person.acted_on_behalf', 'person', (string) $subject->id,
                reason: 'representation '.$representation->public_id,
                after: ['representation' => $representation->public_id, 'representative_person_id' => $account->person_id, 'scope' => $scope->value]);

            return $operation();
        });
    }

    /**
     * PERSON data a representative may see for the given kind of action (E3.7b): the fields configured for
     * that scope (`identity.representation.visible_fields`), never SPECIAL CATEGORY or SECRET ones. Nothing
     * about organizations or other people. An ended representation gives nothing; the history of what was
     * done under it stays in the audit.
     *
     * @return array<string, mixed>
     */
    public function visibleData(User $account, Person $subject, RepresentationScope $scope): array
    {
        if (! $this->allows($account, $subject, $scope)) {
            throw (new AccessDenied('person', $subject->public_id, null, 'representation.'.$scope->value))->hideAsNotFound();
        }
        $classes = $subject->dataClassification();
        $fields = array_filter(
            config('identity.representation.visible_fields')[$scope->value] ?? [],
            fn (string $field) => ! in_array($classes[$field] ?? DataClass::Secret, [DataClass::SpecialCategory, DataClass::Secret], true),
        );

        return $subject->only(array_values($fields));
    }

    public function allows(User $account, Person $subject, RepresentationScope $scope): bool
    {
        if ($account->person_id !== null && $account->person_id === $subject->id) {
            return true;
        }

        return (bool) $this->activeRepresentation($account, $subject)?->allows($scope);
    }

    private function activeRepresentation(User $account, Person $subject): ?Representation
    {
        if ($account->person_id === null) {
            return null;
        }

        return Representation::query()
            ->where('representative_person_id', $account->person_id)
            ->where('represented_person_id', $subject->id)
            ->activeAt(now('UTC'))
            ->first();
    }
}
