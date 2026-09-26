<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Identity\Models\Person;
use App\Domain\Platform\Classification\ClassifiesData;
use App\Domain\Platform\Concerns\AuditsChanges;
use App\Domain\Platform\Enums\DataClass;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['given_name', 'family_name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements ClassifiesData
{
    /** @use HasFactory<UserFactory> */
    use AuditsChanges, HasFactory, Notifiable;

    public function auditSubjectType(): string
    {
        return 'account';
    }

    public function auditOrganizationId(): ?string
    {
        return null;
    }

    public function dataClassification(): array
    {
        return [
            'person_id' => DataClass::Internal,
            'given_name' => DataClass::Restricted,
            'family_name' => DataClass::Restricted,
            'email' => DataClass::Restricted,
            'email_verified_at' => DataClass::Internal,
            'password' => DataClass::Secret,
            'remember_token' => DataClass::Secret,
            'two_factor_secret' => DataClass::Secret,
            'two_factor_recovery_codes' => DataClass::Secret,
            'two_factor_confirmed_at' => DataClass::Internal,
        ];
    }

    /** The PERSON this account gives access to; null until linked (E2.4). */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected static function booted(): void
    {
        static::updating(function (self $user): void {
            if ($user->getOriginal('person_id') !== null && $user->isDirty('person_id')) {
                throw new \LogicException('A linked account cannot move to another person.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
