<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Platform\AuditReason;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ends the account's sessions (A5 §12): removes stored sessions — all, or all but `$keepSessionId` — and
 * rotates the remember-me token so "remember me" cookies stop working.
 */
final class InvalidateAccountSessions
{
    public function __construct(private readonly AuditReason $reason) {}

    public function handle(User $account, string $reason, ?string $keepSessionId = null): int
    {
        $removed = DB::table(config('session.table', 'sessions'))->where('user_id', $account->id)
            ->when($keepSessionId !== null, fn ($query) => $query->where('id', '!=', $keepSessionId))
            ->delete();
        $this->reason->because($reason, fn () => $account->forceFill(['remember_token' => Str::random(60)])->save());

        return $removed;
    }
}
