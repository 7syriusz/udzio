<?php

namespace App\Domain\Identity\Enums;

/**
 * What a representative may do for the represented person (A5 §1.4: representation alone is not full
 * access). Later stages add their scopes here (registrations E5, payments E8, consents E9).
 */
enum RepresentationScope: string
{
    case ProfileView = 'profile.view';
    case ProfileUpdate = 'profile.update';
    case ContactsView = 'contacts.view';
    case ContactsManage = 'contacts.manage';
    case RegistrationsManage = 'registrations.manage';
    case PaymentsManage = 'payments.manage';
    case ConsentsManage = 'consents.manage';

    /** @param list<self> $scopes @return list<string> */
    public static function normalize(array $scopes): array
    {
        $values = array_values(array_unique(array_map(fn (self $scope) => $scope->value, $scopes)));
        sort($values);

        return $values;
    }
}
