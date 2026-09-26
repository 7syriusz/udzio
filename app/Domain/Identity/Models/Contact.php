<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\ContactChannel;
use App\Domain\Platform\Classification\ClassifiesData;
use App\Domain\Platform\Concerns\AuditsChanges;
use App\Domain\Platform\Enums\DataClass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * CONTACT (A5 §1.3): a channel owned by one PERSON. It may be used in another person's matter (a
 * guardian's e-mail for a child) without becoming that person's identity. The value never changes —
 * a new address is a new contact; removal keeps the row (`removed_at`).
 */
class Contact extends Model implements ClassifiesData
{
    use AuditsChanges, HasUlids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'active_key'];

    protected static function booted(): void
    {
        static::updating(function (self $contact): void {
            if ($contact->isDirty(['public_id', 'person_id', 'channel', 'value'])) {
                throw new LogicException('A contact address and its owner cannot change; add a new contact instead.');
            }
            if ($contact->getOriginal('removed_at') !== null) {
                throw new LogicException('A removed contact cannot change.');
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
        return [
            'channel' => ContactChannel::class,
            'verified_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->whereNotNull('verified_at');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function auditSubjectType(): string
    {
        return 'contact';
    }

    public function auditOrganizationId(): ?string
    {
        return null;
    }

    public function dataClassification(): array
    {
        return [
            'public_id' => DataClass::Internal,
            'person_id' => DataClass::Internal,
            'channel' => DataClass::Internal,
            'value' => DataClass::Restricted,
            'verified_at' => DataClass::Internal,
            'removed_at' => DataClass::Internal,
        ];
    }
}
