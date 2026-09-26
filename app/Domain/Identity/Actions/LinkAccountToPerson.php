<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Exceptions\AccountLinkConflict;
use App\Domain\Identity\Models\Person;
use App\Domain\Platform\AuditReason;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Gives an ACCOUNT access to a PERSON (A5-02). The PERSON keeps its identity and history; one PERSON has
 * at most one account and a linked account never moves to another PERSON (unique person_id + locks).
 */
final class LinkAccountToPerson
{
    public function __construct(private readonly AuditReason $reason) {}

    public function handle(User $account, Person $person, string $reason): User
    {
        try {
            return DB::transaction(function () use ($account, $person, $reason): User {
                Person::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();
                $locked = User::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
                if ($locked->person_id === $person->id) {
                    return $locked;
                }
                if ($locked->person_id !== null) {
                    throw new AccountLinkConflict('The account is already linked to another person.');
                }
                if (User::query()->where('person_id', $person->id)->exists()) {
                    throw new AccountLinkConflict('The person already has an account.');
                }
                $this->reason->because($reason, fn () => $locked->forceFill(['person_id' => $person->id])->save());
                $account->setRawAttributes($locked->getAttributes(), true);

                return $account;
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new AccountLinkConflict('The person already has an account.', previous: $e);
            }
            throw $e;
        }
    }
}
