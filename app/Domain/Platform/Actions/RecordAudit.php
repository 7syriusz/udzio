<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Enums\AuditResult;
use App\Domain\Platform\Models\AuditEntry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class RecordAudit
{
    public function __construct(private readonly ActorContext $context) {}

    /** Append on the business connection, inside the caller's transaction. */
    public function handle(
        string $action,
        string $subjectType,
        string $subjectId,
        AuditResult $result = AuditResult::Succeeded,
        ?string $organizationId = null,
        ?string $reason = null,
        ?string $correlationId = null,
        ?array $before = null,
        ?array $after = null,
    ): AuditEntry {
        $actor = $this->context->current();
        $attributes = [
            'public_id' => (string) Str::ulid(),
            'actor_type' => $actor->type->value,
            'actor_id' => $actor->identifier,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'organization_id' => $organizationId,
            'action' => $action,
            'result' => $result->value,
            'reason' => $reason,
            'correlation_id' => $correlationId ?? (string) Str::ulid(),
            'occurred_at' => now('UTC'),
            'before_values' => $before,
            'after_values' => $after,
        ];

        Validator::make($attributes, [
            'actor_id' => ['nullable', 'string', 'max:191'],
            'subject_type' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_.-]*$/'],
            'subject_id' => ['required', 'string', 'max:191'],
            'organization_id' => ['nullable', 'string', 'max:191'],
            'action' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_.-]*$/'],
            'reason' => ['nullable', 'string', 'max:4000'],
            'correlation_id' => ['required', 'ulid'],
        ])->validate();

        $entry = new AuditEntry;
        $entry->forceFill($attributes)->save();

        return $entry;
    }
}
