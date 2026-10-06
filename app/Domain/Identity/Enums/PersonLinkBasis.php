<?php

namespace App\Domain\Identity\Enums;

/**
 * Accepted grounds for linking an account to a PERSON in the repair procedure (Z-022, Z-042). There is no
 * ground for similar names or contact data: similarity alone never links an account to someone's records.
 */
enum PersonLinkBasis: string
{
    /** The account holder confirmed again an e-mail address recorded for that PERSON. */
    case EmailReconfirmed = 'email_reconfirmed';
    /** The account holder confirmed a phone number recorded for that PERSON. */
    case PhoneConfirmed = 'phone_confirmed';
    /** The PERSON concerned (or the other candidates) confirmed who is who. */
    case PersonConfirmation = 'person_confirmation';
    /** An identity document or other reliable evidence was checked. */
    case Document = 'document';
    /** The organizations holding the records confirmed it consistently. */
    case OrganizationsAgreement = 'organizations_agreement';
}
