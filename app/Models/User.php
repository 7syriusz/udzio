<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Platform\Classification\ClassifiesData;
use App\Domain\Platform\Concerns\AuditsChanges;
use App\Domain\Platform\Enums\DataClass;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
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
            'name' => DataClass::Restricted,
            'email' => DataClass::Restricted,
            'email_verified_at' => DataClass::Internal,
            'password' => DataClass::Secret,
            'remember_token' => DataClass::Secret,
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
