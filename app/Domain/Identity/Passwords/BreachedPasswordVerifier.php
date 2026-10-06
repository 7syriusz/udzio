<?php

namespace App\Domain\Identity\Passwords;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Breach check against Have I Been Pwned (E3.8e, Z-041) by k-anonymity: only the first 5 characters of the
 * password's SHA-1 leave the server; the match is found locally among the returned suffixes. When the service
 * cannot answer, the check passes (the local list still applies) and the technical log gets the failure kind
 * only — never the password, its hash or its prefix.
 */
final class BreachedPasswordVerifier
{
    public const ENDPOINT = 'https://api.pwnedpasswords.com/range/';

    public function __construct(private readonly Factory $http) {}

    /** @param array{value: mixed, threshold: int} $data */
    public function verify(array $data): bool
    {
        $value = (string) $data['value'];
        if ($value === '') {
            return false;
        }
        $hash = strtoupper(sha1($value));
        $prefix = substr($hash, 0, 5);

        try {
            $response = $this->http->withHeaders(['Add-Padding' => 'true'])
                ->timeout((int) config('identity.passwords.breach_check.timeout_seconds', 3))
                ->get(self::ENDPOINT.$prefix);
        } catch (Throwable $failure) {
            return $this->unavailable($failure::class);
        }
        if (! $response->successful()) {
            return $this->unavailable('http_'.$response->status());
        }

        foreach (preg_split('/\R/', trim($response->body())) as $line) {
            [$suffix, $count] = array_pad(explode(':', trim($line)), 2, '0');
            if ($prefix.strtoupper($suffix) === $hash && (int) $count > $data['threshold']) {
                return false;
            }
        }

        return true;
    }

    private function unavailable(string $kind): bool
    {
        Log::warning('Password breach check unavailable; password accepted on the local check only.', ['failure' => $kind]);

        return true;
    }
}
