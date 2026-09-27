<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\RepresentationMethod;
use App\Domain\Identity\Enums\RepresentationScope;
use App\Domain\Platform\Classification\ClassifiesData;
use App\Domain\Platform\Concerns\AuditsChanges;
use App\Domain\Platform\Concerns\HasValidityPeriod;
use App\Domain\Platform\Enums\DataClass;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REPRESENTATION as a relation in time (E1.5, Z-025): changing scopes opens a new period, ending it closes
 * the period; history stays. Each period records method, basis and establishing ACTOR. Key: representative
 * + represented person. No special parent–child type: that is one use of a representation.
 */
class Representation extends Model implements ClassifiesData
{
    use AuditsChanges, HasUlids, HasValidityPeriod;

    protected $guarded = ['id'];

    /** @return list<string> */
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
        return ['representative_person_id', 'represented_person_id'];
    }

    protected function casts(): array
    {
        return ['method' => RepresentationMethod::class, 'scopes' => 'array'];
    }

    public function representative(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'representative_person_id');
    }

    public function represented(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'represented_person_id');
    }

    public function allows(RepresentationScope $scope): bool
    {
        return in_array($scope->value, $this->scopes, true);
    }

    public function auditSubjectType(): string
    {
        return 'representation';
    }

    public function auditOrganizationId(): ?string
    {
        return null;
    }

    public function dataClassification(): array
    {
        return [
            'public_id' => DataClass::Internal,
            'representative_person_id' => DataClass::Internal,
            'represented_person_id' => DataClass::Internal,
            'scopes' => DataClass::Internal,
            'method' => DataClass::Internal,
            'basis' => DataClass::Restricted,
            'established_by_type' => DataClass::Internal,
            'established_by_id' => DataClass::Internal,
            'status' => DataClass::Internal,
            'valid_from' => DataClass::Internal,
            'valid_to' => DataClass::Internal,
        ];
    }
}
