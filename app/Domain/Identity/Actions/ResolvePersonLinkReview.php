<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\ContactChannel;
use App\Domain\Identity\Enums\PersonLinkReviewStatus;
use App\Domain\Identity\Models\Person;
use App\Domain\Identity\Models\PersonLinkReview;
use App\Domain\Platform\AuditReason;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Closes the repair procedure of Z-022 after an authorized role has verified the account holder: links the
 * account to the chosen PERSON (one of the candidates), or — when none is the account holder — to a new
 * PERSON created from the account. The permission to call it is added with roles in E3.
 */
final class ResolvePersonLinkReview
{
    public function __construct(
        private readonly LinkAccountToPerson $link,
        private readonly RegisterPerson $register,
        private readonly AddContact $addContact,
        private readonly AuditReason $reason,
    ) {}

    public function handle(PersonLinkReview $review, ?Person $person, string $reason): PersonLinkReview
    {
        return DB::transaction(function () use ($review, $person, $reason): PersonLinkReview {
            $review = PersonLinkReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($review->status !== PersonLinkReviewStatus::Open) {
                throw new LogicException('The review is already resolved.');
            }
            $account = $review->account;
            if ($person === null) {
                $person = $this->register->handle(['given_name' => $account->given_name, 'family_name' => $account->family_name]);
                $contact = $this->addContact->handle($person, ContactChannel::Email, $account->email);
                $this->reason->because($reason, fn () => $contact->update(['verified_at' => $account->email_verified_at]));
            } elseif (! in_array($person->id, $review->candidate_person_ids, true)) {
                throw new LogicException('Only a candidate of the review, or a new person, can be chosen.');
            }
            $this->link->handle($account, $person, $reason);
            $this->reason->because($reason, fn () => $review->update([
                'status' => PersonLinkReviewStatus::Resolved,
                'resolved_person_id' => $person->id,
                'resolved_at' => now('UTC'),
            ]));

            return $review;
        });
    }
}
