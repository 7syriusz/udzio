<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\PersonLinkReviewStatus;
use App\Domain\Platform\Classification\ClassifiesData;
use App\Domain\Platform\Concerns\AuditsChanges;
use App\Domain\Platform\Enums\DataClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Open case of an account that could not be linked to exactly one PERSON (Z-022). Candidates are visible
 * only to the role resolving the case, never to the account holder.
 */
class PersonLinkReview extends Model implements ClassifiesData
{
    use AuditsChanges, HasUlids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'open_key'];

    protected static function booted(): void
    {
        static::updating(function (self $review): void {
            if ($review->getOriginal('status') === PersonLinkReviewStatus::Resolved->value) {
                throw new LogicException('A resolved review cannot change.');
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
            'status' => PersonLinkReviewStatus::class,
            'candidate_person_ids' => 'array',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function auditSubjectType(): string
    {
        return 'person_link_review';
    }

    public function auditOrganizationId(): ?string
    {
        return null;
    }

    public function dataClassification(): array
    {
        return [
            'public_id' => DataClass::Internal,
            'user_id' => DataClass::Internal,
            'status' => DataClass::Internal,
            'candidate_person_ids' => DataClass::Restricted,
            'resolved_person_id' => DataClass::Internal,
            'resolved_at' => DataClass::Internal,
        ];
    }
}
