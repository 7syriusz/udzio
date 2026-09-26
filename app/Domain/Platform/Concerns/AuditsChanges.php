<?php

namespace App\Domain\Platform\Concerns;

use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\AuditReason;
use LogicException;

trait AuditsChanges
{
    abstract public function auditSubjectType(): string;

    abstract public function auditOrganizationId(): ?string;

    /** @return list<string> */
    abstract public function auditVisibleFields(): array;

    /** @return list<string> */
    abstract public function auditRedactedFields(): array;

    /** @param array<string, mixed> $options */
    public function save(array $options = []): bool
    {
        $this->assertAuditConnection();

        return $this->getConnection()->transaction(function () use ($options): bool {
            $existing = $this->exists;
            $before = $existing ? $this->newModelQuery()->whereKey($this->getKey())->lockForUpdate()->firstOrFail()->getAttributes() : [];
            $saved = parent::save($options);

            if ($saved) {
                $after = $this->newModelQuery()->whereKey($this->getKey())->firstOrFail()->getAttributes();
                $this->recordChanges($existing ? 'updated' : 'created', $before, $after);
            }

            return $saved;
        });
    }

    public function delete(): ?bool
    {
        if (! $this->exists) {
            return null;
        }
        $this->assertAuditConnection();

        return $this->getConnection()->transaction(function (): ?bool {
            $before = $this->newModelQuery()->whereKey($this->getKey())->lockForUpdate()->firstOrFail()->getAttributes();
            $deleted = parent::delete();

            if ($deleted) {
                $this->recordChanges('deleted', $before, []);
            }

            return $deleted;
        });
    }

    private function assertAuditConnection(): void
    {
        if ($this->getConnection()->getName() !== config('database.default')) {
            throw new LogicException('Audited models must use the audit business connection.');
        }
        if ($this->exists && $this->isDirty($this->getKeyName())) {
            throw new LogicException('An audited identity cannot be changed.');
        }
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function recordChanges(string $operation, array $before, array $after): void
    {
        $previousValues = [];
        $newValues = [];
        $redacted = $this->auditRedactedFields();
        foreach (array_unique([...$this->auditVisibleFields(), ...$redacted]) as $field) {
            $previous = $before[$field] ?? null;
            $next = $after[$field] ?? null;
            if ($operation === 'updated' && $previous === $next) {
                continue;
            }
            $hide = in_array($field, $redacted, true);
            $previousValues[$field] = $hide && $previous !== null ? '[REDACTED]' : $previous;
            $newValues[$field] = $hide && $next !== null ? '[REDACTED]' : $next;
        }
        if ($previousValues === []) {
            return;
        }
        $reason = app(AuditReason::class)->current();
        if ($reason === null && $operation !== 'created') {
            throw new LogicException('A reason is required for an audited change.');
        }

        app(RecordAudit::class)->handle(
            $this->auditSubjectType().'.'.$operation,
            $this->auditSubjectType(),
            (string) $this->getKey(),
            organizationId: $this->auditOrganizationId(),
            reason: $reason ?? 'record.created',
            before: $previousValues,
            after: $newValues,
        );
    }
}
