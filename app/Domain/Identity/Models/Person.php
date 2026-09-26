<?php

namespace App\Domain\Identity\Models;

use App\Domain\Platform\Classification\ClassifiesData;
use App\Domain\Platform\Concerns\AuditsChanges;
use App\Domain\Platform\Enums\DataClass;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * PERSON (A5 §1.1). Global: it has no organization or scenario of its own — contexts refer to it
 * through relations, so a new context never creates a second PERSON (A5-01). It may exist without
 * an ACCOUNT (A5-02). Externally identified only by `public_id`.
 */
class Person extends Model implements ClassifiesData
{
    /** @use HasFactory<PersonFactory> */
    use AuditsChanges, HasFactory, HasUlids;

    protected $table = 'people';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['given_name', 'family_name', 'birth_date'];

    protected static function booted(): void
    {
        static::updating(function (self $person): void {
            if ($person->isDirty('public_id')) {
                throw new LogicException('The public identifier of a PERSON cannot change.');
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
        return ['birth_date' => 'immutable_date'];
    }

    public function fullName(): string
    {
        return $this->given_name.' '.$this->family_name;
    }

    public function auditSubjectType(): string
    {
        return 'person';
    }

    public function auditOrganizationId(): ?string
    {
        return null;
    }

    public function dataClassification(): array
    {
        return [
            'public_id' => DataClass::Internal,
            'given_name' => DataClass::Restricted,
            'family_name' => DataClass::Restricted,
            'birth_date' => DataClass::Restricted,
        ];
    }

    protected static function newFactory(): PersonFactory
    {
        return PersonFactory::new();
    }
}
