<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\Contact;
use App\Domain\Platform\AuditReason;

/** Ends the use of a contact. The row stays for history; its pending verification codes stop working. */
final class RemoveContact
{
    public function __construct(private readonly AuditReason $reason) {}

    public function handle(Contact $contact, string $reason): Contact
    {
        return $this->reason->because($reason, fn () => $contact->getConnection()->transaction(function () use ($contact): Contact {
            $contact->update(['removed_at' => now('UTC')]);
            $contact->getConnection()->table('contact_verifications')->where('contact_id', $contact->id)
                ->whereNull('consumed_at')->update(['consumed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);

            return $contact;
        }));
    }
}
