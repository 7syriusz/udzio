<?php

namespace App\Domain\Organization\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The single record of the platform installation (E3.8b): id 1, written together with the first platform
 * administrator. Its existence closes the installation procedure for good.
 */
class PlatformInstallation extends Model
{
    public const ID = 1;

    public $incrementing = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $installation): void {
            if (array_diff(array_keys($installation->getDirty()), ['administrator_user_id', 'updated_at']) !== [] || $installation->getOriginal('administrator_user_id') !== null) {
                throw new LogicException('The platform installation record cannot change.');
            }
        });
    }

    protected function casts(): array
    {
        return ['installed_at' => 'immutable_datetime'];
    }

    /** Completes the record with the account created in the same installation transaction (once). */
    public function recordAdministrator(User $account): void
    {
        $this->forceFill(['administrator_user_id' => $account->getKey()])->save();
    }

    public static function completed(): bool
    {
        return static::query()->whereKey(self::ID)->exists();
    }

    public function delete(): ?bool
    {
        throw new LogicException('The platform installation record cannot be deleted.');
    }
}
