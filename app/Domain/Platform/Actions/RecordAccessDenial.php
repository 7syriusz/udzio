<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Enums\AuditResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Records a denied operation (A5-14) on the independent `audit` connection, so the fact survives
 * the rollback of the business transaction that was refused. Writes are limited per actor and
 * target to keep a flood of denials from filling the audit table; skipped writes are logged.
 */
final class RecordAccessDenial
{
    public const MAX_PER_MINUTE = 20;

    public function __construct(
        private readonly RecordAudit $audit,
        private readonly ActorContext $context,
    ) {}

    /** @param array<string, mixed> $details Non-sensitive metadata only (request data or decision basis). */
    public function handle(
        string $subjectType,
        string $subjectId,
        ?string $organizationId = null,
        array $details = [],
    ): bool {
        $actor = $this->context->current();
        $key = 'audit-denial:'.sha1($actor->type->value.'|'.$actor->identifier.'|'.$subjectType.'|'.$subjectId);

        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_MINUTE)) {
            Log::warning('Access denial audit throttled.', ['actor_type' => $actor->type->value, 'subject_type' => $subjectType]);

            return false;
        }
        RateLimiter::hit($key, 60);

        $this->audit->handle(
            'access.denied',
            $subjectType,
            $subjectId,
            AuditResult::Denied,
            organizationId: $organizationId,
            after: $details === [] ? null : $details,
            connection: 'audit',
        );

        return true;
    }
}
