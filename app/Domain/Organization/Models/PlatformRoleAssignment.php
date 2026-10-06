<?php

namespace App\Domain\Organization\Models;

use App\Domain\Platform\Classification\ClassifiesData;
use App\Domain\Platform\Concerns\AuditsChanges;
use App\Domain\Platform\Concerns\HasValidityPeriod;
use App\Domain\Platform\Enums\DataClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * ACCOUNT → platform role for a period (E3.8b, E1.5). Separate from organization role assignments; it gives
 * nothing by itself — PlatformAccess decides, and only with a verified e-mail and confirmed MFA.
 */
class PlatformRoleAssignment extends Model implements ClassifiesData
{
    use AuditsChanges, HasUlids, HasValidityPeriod;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $assignment): void {
            if (array_diff(array_keys($assignment->getDirty()), ['valid_to', 'updated_at']) !== []) {
                throw new LogicException('Platform role assignments cannot be rewritten; open a new period.');
            }
        });
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public static function validityKey(): array
    {
        return ['user_id', 'role'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function delete(): ?bool
    {
        throw new LogicException('Platform role history cannot be deleted.');
    }

    public function auditSubjectType(): string
    {
        return 'platform_role_assignment';
    }

    public function auditOrganizationId(): ?string
    {
        return null;
    }

    public function dataClassification(): array
    {
        return array_fill_keys(['public_id', 'user_id', 'role', 'status', 'valid_from', 'valid_to'], DataClass::Internal);
    }
}
