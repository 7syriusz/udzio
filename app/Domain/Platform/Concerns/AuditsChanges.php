<?php

namespace App\Domain\Platform\Concerns;

use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Classification\ClassifiedData;
use App\Domain\Platform\Classification\ClassifiesData;
use App\Domain\Platform\Enums\DataClass;
use LogicException;

/** Models using this trait must implement ClassifiesData. */
trait AuditsChanges
{
    abstract public function auditSubjectType(): string;

    abstract public function auditOrganizationId(): ?string;

    /**
     * Every written field must be classified (A5 §12); the class decides how it appears in the audit.
     *
     * @return array<string, DataClass>
     */
    abstract public function dataClassification(): array;

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
        if (! $this instanceof ClassifiesData) {
            throw new LogicException(static::class.' must implement '.ClassifiesData::class.'.');
        }
        if ($this->getConnection()->getName() !== config('database.default')) {
            throw new LogicException('Audited models must use the audit business connection.');
        }
        if ($this->exists && $this->isDirty($this->getKeyName())) {
            throw new LogicException('An audited identity cannot be changed.');
        }
        $technical = [$this->getKeyName(), $this->getCreatedAtColumn(), $this->getUpdatedAtColumn()];
        $unclassified = array_diff(array_keys($this->getDirty()), array_keys($this->dataClassification()), $technical);
        if ($unclassified !== []) {
            throw new LogicException('Unclassified fields cannot be written: '.implode(', ', $unclassified).'.');
        }
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function recordChanges(string $operation, array $before, array $after): void
    {
        $previousValues = [];
        $newValues = [];
        foreach (array_keys($this->dataClassification()) as $field) {
            $previous = $before[$field] ?? null;
            $next = $after[$field] ?? null;
            if ($operation === 'updated' && $previous === $next) {
                continue;
            }
            $previousValues[$field] = $previous;
            $newValues[$field] = $next;
        }
        $previousValues = ClassifiedData::forAudit($this, $previousValues);
        $newValues = ClassifiedData::forAudit($this, $newValues);
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
