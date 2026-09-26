<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\ContactChannel;
use App\Domain\Identity\Models\Contact;
use App\Domain\Identity\Notifications\ContactVerificationCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Sends a one-time numeric code to the contact. Only a keyed hash of the code is stored; a new request
 * invalidates earlier codes. Phone delivery needs an SMS operator (not configured yet, Z-020).
 */
final class RequestContactVerification
{
    public function handle(Contact $contact): void
    {
        if ($contact->removed_at !== null || $contact->isVerified()) {
            throw new LogicException('Only an active, unverified contact can be verified.');
        }
        $settings = config('identity.contacts.verification');
        $limiterKey = 'contact-verification:'.$contact->id;
        if (! RateLimiter::attempt($limiterKey, $settings['max_requests_per_hour'], fn () => true, 3600)) {
            throw ValidationException::withMessages(['contact' => 'Zbyt wiele próśb o kod. Spróbuj później.']);
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

        match ($contact->channel) {
            ContactChannel::Email => Notification::route('mail', $contact->value)
                ->notify(new ContactVerificationCode($code, $settings['ttl_minutes'])),
            ContactChannel::Phone => Log::warning('SMS operator not configured; phone verification code not delivered.', ['contact' => $contact->public_id]),
        };
    }

    public static function hash(Contact $contact, string $code): string
    {
        return hash_hmac('sha256', $contact->id.'|'.$code, (string) config('app.key'));
    }
}
