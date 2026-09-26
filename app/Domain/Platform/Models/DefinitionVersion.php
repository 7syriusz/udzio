<?php

namespace App\Domain\Platform\Models;

use App\Domain\Platform\Enums\ActorType;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A frozen snapshot of a definition. Results point here, never to the editable definition. */
class DefinitionVersion extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Definition versions are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Definition versions are immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'version' => 'integer',
            'published_by_type' => ActorType::class,
            'published_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
