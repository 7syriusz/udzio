<?php

namespace App\Domain\Organization\Models;

use App\Domain\Platform\Classification\ClassifiesData;
use App\Domain\Platform\Concerns\AuditsChanges;
use App\Domain\Platform\Concerns\HasValidityPeriod;
use App\Domain\Platform\Enums\DataClass;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Historical parent assignment. Writes go through MoveOrganization, which guards the whole graph. */
class OrganizationParent extends Model implements ClassifiesData
{
    use AuditsChanges, HasUlids, HasValidityPeriod;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $period): void {
            if (array_diff(array_keys($period->getDirty()), ['valid_to', 'updated_at']) !== []) {
                throw new LogicException('Parent assignments cannot be rewritten; open a new period.');
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
        return ['organization_id'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'parent_id');
    }

    public function delete(): ?bool
    {
        throw new LogicException('Organization hierarchy history cannot be deleted.');
    }

    public function auditSubjectType(): string
    {
        return 'organization_parent';
    }

    public function auditOrganizationId(): ?string
    {
        return $this->organization->public_id;
    }

    public function dataClassification(): array
    {
        return array_fill_keys(['public_id', 'organization_id', 'parent_id', 'status', 'valid_from', 'valid_to'], DataClass::Internal);
    }
}
