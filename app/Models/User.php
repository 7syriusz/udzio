<?php

namespace App\Models;

use App\Domain\Identity\Enums\PersonLinkReviewStatus;
use App\Domain\Identity\Models\Person;
use App\Domain\Identity\Models\PersonLinkReview;
use App\Domain\Platform\AuditReason;
use App\Domain\Platform\Classification\ClassifiesData;
use App\Domain\Platform\Concerns\AuditsChanges;
use App\Domain\Platform\Enums\DataClass;
use App\Domain\Platform\Localization\LocaleResolver;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['given_name', 'family_name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements ClassifiesData, HasLocalePreference, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use AuditsChanges, HasFactory, Notifiable, TwoFactorAuthenticatable {
        TwoFactorAuthenticatable::replaceRecoveryCode as private replaceRecoveryCodeUnaudited;
    }

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
            'locale' => DataClass::Internal,
            'email_verified_at' => DataClass::Internal,
            'password' => DataClass::Secret,
            'remember_token' => DataClass::Secret,
            'two_factor_secret' => DataClass::Secret,
            'two_factor_recovery_codes' => DataClass::Secret,
            'two_factor_confirmed_at' => DataClass::Internal,
        ];
    }

    /** A used recovery code is replaced by a new one; the write is audited (values stay SECRET). */
    public function replaceRecoveryCode($code): void
    {
        app(AuditReason::class)->because('recovery code used at login', fn () => $this->replaceRecoveryCodeUnaudited($code));
    }

    /** Language of mail and notifications sent to the account (Laravel uses it automatically). */
    public function preferredLocale(): string
    {
        return app(LocaleResolver::class)->forAccount($this);
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function markEmailAsVerified(): bool
    {
        return app(AuditReason::class)->because('account e-mail verified by signed link', fn () => $this->forceFill(['email_verified_at' => $this->freshTimestamp()])->save());
    }

    /** Open repair procedure when the account could not be linked to exactly one PERSON (Z-022). */
    public function openPersonLinkReview(): HasOne
    {
        return $this->hasOne(PersonLinkReview::class)->where('status', PersonLinkReviewStatus::Open);
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
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
