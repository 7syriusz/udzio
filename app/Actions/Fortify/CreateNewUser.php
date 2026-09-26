<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $input['email'] = mb_strtolower(trim((string) ($input['email'] ?? '')));
        Validator::make($input, [
            'given_name' => ['required', 'string', 'max:100', 'regex:/\S/'],
            'family_name' => ['required', 'string', 'max:100', 'regex:/\S/'],
            'email' => [
                'required',
                'string',
                'email',
                'max:254',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        // A new account is not a PERSON yet: it is linked after e-mail verification (E2.4, A5-02).
        return User::create([
            'given_name' => trim($input['given_name']),
            'family_name' => trim($input['family_name']),
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
        ]);
    }
}
