<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\ContactChannel;
use App\Domain\Identity\Enums\PersonLinkReviewStatus;
use App\Domain\Identity\Models\Contact;
use App\Domain\Identity\Models\Person;
use App\Domain\Identity\Models\PersonLinkReview;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Enums\AuditResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * After the account e-mail is verified (A5-02, Z-022): link the account to the one PERSON that has the
 * same verified e-mail contact, or create a new PERSON when there is none. Several candidates, or a
 * candidate that already has an account, are a conflict: nothing is linked or merged, and a controlled
 * repair procedure (PersonLinkReview) is opened. Unverified contacts never cause a link.
 */
final class ResolveAccountPerson
{
    public const LINKED = 'linked';

    public const CREATED = 'created';

    public const CONFLICT = 'conflict';

    public const UNCHANGED = 'unchanged';

    public function __construct(
        private readonly LinkAccountToPerson $link,
        private readonly RegisterPerson $register,
        private readonly AddContact $addContact,
        private readonly AuditReason $reason,
        private readonly RecordAudit $audit,
    ) {}

    /** @param list<int> $candidates */
    private function openReview(User $account, array $candidates): void
    {
        if (PersonLinkReview::query()->where('user_id', $account->id)->where('status', PersonLinkReviewStatus::Open)->exists()) {
            return;
        }
        PersonLinkReview::create(['user_id' => $account->id, 'status' => PersonLinkReviewStatus::Open, 'candidate_person_ids' => $candidates]);
    }

    public function handle(User $account): string
    {
        if ($account->person_id !== null || ! $account->hasVerifiedEmail()) {
            return self::UNCHANGED;
        }

        return DB::transaction(function () use ($account): string {
            $candidates = Contact::query()->active()->verified()
                ->where('channel', ContactChannel::Email)->where('value', mb_strtolower($account->email))
                ->lockForUpdate()->distinct()->pluck('person_id');

            if ($candidates->count() === 1 && ! User::query()->where('person_id', $candidates->first())->exists()) {
                $this->link->handle($account, Person::query()->findOrFail($candidates->first()), 'verified account e-mail matches verified contact of person');

                return self::LINKED;
            }
            if ($candidates->isNotEmpty()) {
                $this->audit->handle('account.person_link_conflict', 'account', (string) $account->id, AuditResult::Failed,
                    reason: 'verified account e-mail matches verified contacts of several people or of a person with another account',
                    after: ['candidates' => $candidates->count()]);
                $this->openReview($account, $candidates->map(fn ($id) => (int) $id)->sort()->values()->all());

                return self::CONFLICT;
            }

            $person = $this->register->handle(['given_name' => $account->given_name, 'family_name' => $account->family_name]);
            $contact = $this->addContact->handle($person, ContactChannel::Email, $account->email);
            $this->reason->because('verified through account e-mail', fn () => $contact->update(['verified_at' => $account->email_verified_at]));
            $this->link->handle($account, $person, 'new person created from verified account');

            return self::CREATED;
        });
    }
}
