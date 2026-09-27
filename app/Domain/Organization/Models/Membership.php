<?php

namespace App\Domain\Organization\Models;

use App\Domain\Identity\Models\Person;
use App\Domain\Platform\Classification\ClassifiesData;
use App\Domain\Platform\Concerns\AuditsChanges;
use App\Domain\Platform\Concerns\HasValidityPeriod;
use App\Domain\Platform\Enums\DataClass;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** A relation role, not an access role: membership alone grants no permissions. */
class Membership extends Model implements ClassifiesData
{
    use AuditsChanges, HasUlids, HasValidityPeriod;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $period): void {
            if (array_diff(array_keys($period->getDirty()), ['valid_to', 'updated_at']) !== []) {
                throw new LogicException('Membership periods cannot be rewritten; open a new period.');
            }
        });
    }

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public static function validityKey(): array
    {
        return ['person_id', 'organization_id'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function transferredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'transferred_from_id');
    }

    public function delete(): ?bool
    {
        throw new LogicException('Membership history cannot be deleted.');
    }

    public function auditSubjectType(): string
    {
        return 'membership';
    }

    public function auditOrganizationId(): ?string
    {
        return $this->organization->public_id;
    }

    public function dataClassification(): array
    {
        return array_fill_keys(['public_id', 'person_id', 'organization_id', 'function', 'status', 'valid_from', 'valid_to', 'transferred_from_id'], DataClass::Internal);
    }
}
