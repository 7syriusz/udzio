<?php

namespace App\Domain\Identity\Verification;

use App\Domain\Identity\Contracts\ContactCodeSender;
use App\Domain\Identity\Models\Contact;
use App\Domain\Identity\Notifications\ContactVerificationCode;
use App\Domain\Platform\Localization\RecipientLocale;
use Illuminate\Support\Facades\Notification;

class MailContactCodeSender implements ContactCodeSender
{
    public function __construct(private readonly RecipientLocale $locales) {}

    public function send(Contact $contact, string $code, int $ttlMinutes): void
    {
        Notification::route('mail', $contact->value)->notify((new ContactVerificationCode($code, $ttlMinutes))->locale($this->locales->forPerson($contact->person_id)));
    }
}
