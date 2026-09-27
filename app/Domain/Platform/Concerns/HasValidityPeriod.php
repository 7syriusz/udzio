<?php

namespace App\Domain\Platform\Concerns;

use App\Domain\Platform\Enums\RelationStatus;
use App\Domain\Platform\Exceptions\ValidityConflict;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Relation in time (A5 §2.3, A5-05): a relation is a sequence of validity periods. A change of
 * status or of the relation's attributes closes the current period and opens a new one; nothing
 * is overwritten or deleted, so the relation can be read "as of" any moment.
 *
 * The model declares `validityKey()` (columns identifying one relation) and its table is built
 * with ValidityColumns::add(). At most one open period per relation is guaranteed by the database.
 * Combine with AuditsChanges to require a reason and record every period change.
 *
 * @mixin Model
 */
trait HasValidityPeriod
{
    /** @return list<string> */
    abstract public static function validityKey(): array;

    public static function bootHasValidityPeriod(): void
    {
        static::updating(function (self $period): void {
            if ($period->isDirty('valid_from')) {
                throw new LogicException('The start of a validity period cannot be changed.');
            }
            if ($period->getOriginal('valid_to') !== null) {
                throw new LogicException('A closed validity period is history and cannot be changed.');
            }
        });
    }

    public function initializeHasValidityPeriod(): void
    {
        $this->mergeCasts([
            'status' => RelationStatus::class,
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
        ]);
        $this->guarded = [...$this->guarded, 'open_key'];
    }

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.u';
    }

    /** Periods in which the relation is in force (status active) at the given moment. */
    public function scopeActiveAt(Builder $query, DateTimeInterface $moment): Builder
    {
        return $this->scopeEffectiveAt($query, $moment)->where('status', RelationStatus::Active->value);
    }

    /** Periods covering the given moment, whatever their status. */
    public function scopeEffectiveAt(Builder $query, DateTimeInterface $moment): Builder
    {
        $at = CarbonImmutable::instance($moment)->utc()->format('Y-m-d H:i:s.u');

        return $query->where('valid_from', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $at));
    }

    /**
     * Opens a new relation (or a new period after the relation ended).
     *
     * @param  array<string, mixed>  $attributes  Key columns and relation attributes.
     */
    public static function startPeriod(array $attributes, DateTimeInterface $from, RelationStatus $status = RelationStatus::Active): static
    {
        $from = CarbonImmutable::instance($from)->utc();

        return DB::connection((new static)->getConnectionName())->transaction(function () use ($attributes, $from, $status): static {
            static::assertNoOverlap($attributes, $from);
            $period = new static;
            $period->forceFill([...$attributes, 'status' => $status, 'valid_from' => $from, 'valid_to' => null]);

            return static::guardOpenPeriod(fn () => tap($period)->save());
        }, attempts: 3);
    }

    /**
     * Closes this (open) period at `$at` and opens the next one with the given status and attribute
     * changes. Used for suspension, resumption and change of function.
     *
     * @param  array<string, mixed>  $changes
     */
    public function transition(RelationStatus $status, DateTimeInterface $at, array $changes = []): static
    {
        $at = CarbonImmutable::instance($at)->utc();

        return $this->getConnection()->transaction(function () use ($status, $at, $changes): static {
            $current = $this->lockedOpenPeriod($at);
            $current->forceFill(['valid_to' => $at])->save();
            $next = $current->replicate(['open_key', 'valid_to']);
            $next->forceFill([...$changes, 'status' => $status, 'valid_from' => $at]);
            foreach (static::validityKey() as $column) {
                if ($next->getAttribute($column) != $current->getAttribute($column)) {
                    throw new LogicException('A transition cannot change the relation key; start a new relation instead.');
                }
            }

            return static::guardOpenPeriod(fn () => tap($next)->save());
        });
    }

    /** Ends the relation at `$at`: the open period is closed, no new period is opened. */
    public function end(DateTimeInterface $at): static
    {
        $at = CarbonImmutable::instance($at)->utc();

        return $this->getConnection()->transaction(function () use ($at): static {
            return tap($this->lockedOpenPeriod($at), fn (self $period) => $period->forceFill(['valid_to' => $at])->save());
        });
    }

    /** All periods of this relation, oldest first. */
    public function history(): Collection
    {
        return static::query()->where($this->keyValues())->orderBy('valid_from')->orderBy($this->getKeyName())->get();
    }

    /** @return array<string, mixed> */
    public function keyValues(): array
    {
        return array_combine(static::validityKey(), array_map(fn ($c) => $this->getAttribute($c), static::validityKey()));
    }

    private function lockedOpenPeriod(CarbonImmutable $at): static
    {
        /** @var static|null $current */
        $current = static::query()->whereKey($this->getKey())->lockForUpdate()->first();
        if ($current === null || $current->valid_to !== null) {
            throw new ValidityConflict('Only an open period can be closed or changed.');
        }
        if ($at->lessThanOrEqualTo($current->valid_from)) {
            throw new ValidityConflict('A period must end after it started.');
        }

        return $current;
    }

    /** @param array<string, mixed> $attributes */
    private static function assertNoOverlap(array $attributes, CarbonImmutable $from): void
    {
        $key = array_intersect_key($attributes, array_flip(static::validityKey()));
        if (count($key) !== count(static::validityKey())) {
            throw new LogicException('All validity key columns are required.');
        }
        $overlapping = static::query()->where($key)->lockForUpdate()
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $from->format('Y-m-d H:i:s.u')))
            ->exists();
        if ($overlapping) {
            throw new ValidityConflict('The relation already has a period covering this moment.');
        }
    }

    /** @param callable(): static $save */
    private static function guardOpenPeriod(callable $save): static
    {
        try {
            return $save();
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), 'open_key')) {
                throw new ValidityConflict('The relation already has an open period.', previous: $e);
            }
            throw $e;
        }
    }
}
