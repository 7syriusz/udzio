<?php

namespace App\Domain\Platform\Models;

use App\Domain\Platform\Enums\ActorType;
use App\Domain\Platform\Enums\AuditResult;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditEntry extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Audit entries are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit entries are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'result' => AuditResult::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
