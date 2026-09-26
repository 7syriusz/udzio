<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\Person;
use Illuminate\Support\Facades\Validator;

/**
 * Creates a PERSON. Always a new identity: no automatic matching by name or birth date, because a
 * wrong match would join two real people. Duplicates are resolved by MERGE (A5-13). See Z-019.
 */
final class RegisterPerson
{
    /** @param array{given_name: string, family_name: string, birth_date?: ?string} $data */
    public function handle(array $data): Person
    {
        return Person::create(Validator::make($data, PersonRules::rules())->validate());
    }
}
