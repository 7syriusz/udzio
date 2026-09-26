<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\RepresentationKind;
use App\Domain\Identity\Enums\RepresentationScope;
use App\Domain\Platform\Classification\ClassifiesData;
use App\Domain\Platform\Concerns\AuditsChanges;
use App\Domain\Platform\Concerns\HasValidityPeriod;
use App\Domain\Platform\Enums\DataClass;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Representation as a relation in time (E1.5): changing scopes or kind opens a new period, ending it
 * closes the period; history stays. Key: representative + represented person.
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
        return ['kind' => RepresentationKind::class, 'scopes' => 'array'];
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
            'kind' => DataClass::Internal,
            'scopes' => DataClass::Internal,
            'status' => DataClass::Internal,
            'valid_from' => DataClass::Internal,
            'valid_to' => DataClass::Internal,
        ];
    }
}
