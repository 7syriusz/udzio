<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\Contact;
use App\Domain\Identity\Verification\ContactCodeSenders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Sends a one-time numeric code through the channel's configured sender. Only a keyed hash of the code is
 * stored; a new request invalidates earlier codes. A channel without a sender (phone until an SMS operator
 * is chosen, Z-020) throws ContactChannelUnavailable before any code is created.
 */
final class RequestContactVerification
{
    public function handle(Contact $contact): void
    {
        if ($contact->removed_at !== null || $contact->isVerified()) {
            throw new LogicException('Only an active, unverified contact can be verified.');
        }
        $sender = ContactCodeSenders::for($contact->channel);
        $settings = config('identity.contacts.verification');
        $limiterKey = 'contact-verification:'.$contact->id;
        if (! RateLimiter::attempt($limiterKey, $settings['max_requests_per_hour'], fn () => true, 3600)) {
            throw ValidationException::withMessages(['contact' => __('identity.contact.too_many_code_requests')]);
        }

        $code = str_pad((string) random_int(0, 10 ** $settings['code_length'] - 1), $settings['code_length'], '0', STR_PAD_LEFT);
        DB::transaction(function () use ($contact, $code, $settings): void {
            $now = now('UTC');
            DB::table('contact_verifications')->where('contact_id', $contact->id)->whereNull('consumed_at')
                ->update(['consumed_at' => $now->format('Y-m-d H:i:s.u')]);
            DB::table('contact_verifications')->insert([
                'contact_id' => $contact->id,
                'code_hash' => self::hash($contact, $code),
                'expires_at' => $now->copy()->addMinutes($settings['ttl_minutes'])->format('Y-m-d H:i:s.u'),
                'created_at' => $now->format('Y-m-d H:i:s.u'),
            ]);
        });

        $sender->send($contact, $code, $settings['ttl_minutes']);
    }

    public static function hash(Contact $contact, string $code): string
    {
        return hash_hmac('sha256', $contact->id.'|'.$code, (string) config('app.key'));
    }
}
