<?php

namespace App\Domain\Identity\Contracts;

use App\Domain\Identity\Models\Contact;

/**
 * Delivers a one-time verification code to a contact. One implementation per channel, registered in
 * config/identity.php (`contacts.senders`). A channel without a sender cannot be verified at all (Z-020).
 * Implementations must not log the code.
 */
interface ContactCodeSender
{
    public function send(Contact $contact, string $code, int $ttlMinutes): void;
}
