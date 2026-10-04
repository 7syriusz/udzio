<?php

use App\Domain\Identity\Verification\MailContactCodeSender;

/*
| Identity settings (E2). Functional assumptions are listed in docs/ZALOZENIA.md (Z-020).
*/

return [
    'contacts' => [
        // Country calling code added to national phone numbers written without a prefix.
        'default_phone_country_code' => '48',
        // Code delivery per channel (App\Domain\Identity\Contracts\ContactCodeSender). null = the channel
        // cannot be verified and no code is ever created or announced (Z-020). Phone: set when an SMS
        // operator is chosen.
        'senders' => [
            'email' => MailContactCodeSender::class,
            'phone' => null,
        ],
        'verification' => [
            'code_length' => 6,
            'ttl_minutes' => 30,
            'max_attempts' => 5,
            'max_requests_per_hour' => 5,
        ],
    ],

    'representation' => [
        // Methods this configuration accepts for establishing a representation (Z-025). The confirmation
        // behind each (acceptance record, document number, decision id) is referenced by `basis`.
        'methods' => ['parties_acceptance', 'declaration', 'role_decision', 'document', 'additional_verification'],
        // SCOPE a representation may grant here. Narrow it per configuration; nothing outside is grantable.
        'grantable_scopes' => ['profile.view', 'profile.update', 'contacts.view', 'contacts.manage',
            'registrations.manage', 'payments.manage', 'consents.manage'],
        // PERSON fields a representative may read for each kind of action (E3.7b, Z-037): only what the action
        // needs. Fields of class SPECIAL CATEGORY or SECRET are never shown through a representation, even
        // when listed here. Later stages add their scopes (registrations E5, payments E8, consents E9).
        'visible_fields' => [
            'profile.view' => ['public_id', 'given_name', 'family_name', 'birth_date'],
            'profile.update' => ['public_id', 'given_name', 'family_name', 'birth_date'],
            'contacts.view' => ['public_id', 'given_name', 'family_name'],
            'contacts.manage' => ['public_id', 'given_name', 'family_name'],
        ],
    ],
];
