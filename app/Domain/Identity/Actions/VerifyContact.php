<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Exceptions\ContactVerificationFailed;
use App\Domain\Identity\Models\Contact;
use App\Domain\Platform\AuditReason;
use Illuminate\Support\Facades\DB;

/** Confirms that the owner controls the contact. Wrong codes count; after the limit the code is dead. */
final class VerifyContact
{
    public function __construct(private readonly AuditReason $reason) {}

    public function handle(Contact $contact, string $code): Contact
    {
        $verified = DB::transaction(function () use ($contact, $code): bool {
            $pending = DB::table('contact_verifications')->where('contact_id', $contact->id)
                ->whereNull('consumed_at')->where('expires_at', '>', now('UTC')->format('Y-m-d H:i:s.u'))
                ->orderByDesc('id')->lockForUpdate()->first();
            if ($pending === null || $pending->attempts >= config('identity.contacts.verification.max_attempts')) {
                return false;
            }
            if (! hash_equals($pending->code_hash, RequestContactVerification::hash($contact, trim($code)))) {
                DB::table('contact_verifications')->where('id', $pending->id)->increment('attempts');

                return false;
            }
            DB::table('contact_verifications')->where('id', $pending->id)->update(['consumed_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
            $this->reason->because('contact verified by code', fn () => $contact->update(['verified_at' => now('UTC')]));

            return true;
        });

        // Failed attempts are committed (not rolled back) so the attempt limit holds.
        if (! $verified) {
            throw new ContactVerificationFailed;
        }

        return $contact;
    }
}
