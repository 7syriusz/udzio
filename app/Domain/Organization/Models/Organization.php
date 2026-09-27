<?php

namespace App\Domain\Organization\Models;

use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Platform\Classification\ClassifiesData;
use App\Domain\Platform\Concerns\AuditsChanges;
use App\Domain\Platform\Enums\DataClass;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** ORGANIZATION (A5 §2.1), independent of PERSON and industry-specific scenarios. */
class Organization extends Model implements ClassifiesData
{
    /** @use HasFactory<OrganizationFactory> */
    use AuditsChanges, HasFactory, HasUlids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['name'];

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::updating(function (self $organization): void {
            if ($organization->isDirty('public_id')) {
                throw new LogicException('The public identifier of an ORGANIZATION cannot change.');
            }
        });
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return ['status' => OrganizationStatus::class];
    }

    public function delete(): ?bool
    {
        throw new LogicException('Organizations must be archived, not deleted.');
    }

    public function auditSubjectType(): string
    {
        return 'organization';
    }

    public function auditOrganizationId(): ?string
    {
        return $this->public_id;
    }

    public function dataClassification(): array
    {
        return [
            'public_id' => DataClass::Internal,
            'name' => DataClass::Internal,
            'status' => DataClass::Internal,
        ];
    }

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }
}
