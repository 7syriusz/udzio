<?php

namespace App\Domain\Identity\Verification;

use App\Domain\Identity\Contracts\ContactCodeSender;
use App\Domain\Identity\Enums\ContactChannel;
use App\Domain\Identity\Exceptions\ContactChannelUnavailable;

/** Resolves the configured sender of a channel (`identity.contacts.senders`). */
final class ContactCodeSenders
{
    public static function supports(ContactChannel $channel): bool
    {
        return is_string(config('identity.contacts.senders.'.$channel->value));
    }

    public static function for(ContactChannel $channel): ContactCodeSender
    {
        $class = config('identity.contacts.senders.'.$channel->value);
        if (! is_string($class)) {
            throw new ContactChannelUnavailable("Verification of {$channel->value} contacts is not available.");
        }

        return app($class);
    }
}
