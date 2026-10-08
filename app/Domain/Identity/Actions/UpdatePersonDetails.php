<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\Person;
use App\Domain\Platform\AuditReason;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

/** Corrects a PERSON's basic data. The identity (id, public_id) never changes; the reason is audited. */
final class UpdatePersonDetails
{
    public function __construct(private readonly AuditReason $reason) {}

    /** @param array{given_name?: string, family_name?: string} $data */
    public function handle(Person $person, array $data, string $reason): Person
    {
        $rules = Arr::only(PersonRules::rules(), array_keys($data));
        $validated = Validator::make($data, array_map(fn (array $r) => ['sometimes', ...$r], $rules))->validate();

        return $this->reason->because($reason, fn () => tap($person)->update($validated));
    }
}
