<?php

namespace App\Domain\Platform;

use Closure;

final class ActorContext
{
    /** @var list<array{scope: string, actor: Actor|Closure(): Actor}> */
    private array $frames = [];

    public function __construct(private readonly Actor $fallback) {}

    public function current(): Actor
    {
        $actor = $this->frames === [] ? $this->fallback : $this->frames[array_key_last($this->frames)]['actor'];

        return $actor instanceof Closure ? $actor() : $actor;
    }

    /**
     * Trusted application code only; this does not grant permissions or represent a SUBJECT.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function runAs(Actor $actor, Closure $operation): mixed
    {
        return $this->within($actor, $operation);
    }

    /**
     * @template T
     *
     * @param  Actor|Closure(): Actor  $actor
     * @param  Closure(): T  $operation
     * @return T
     */
    public function within(Actor|Closure $actor, Closure $operation): mixed
    {
        $previous = $this->frames;
        $this->enter('scope', $actor);

        try {
            return $operation();
        } finally {
            $this->frames = $previous;
        }
    }

    /** @param Actor|Closure(): Actor $actor */
    public function enter(string $scope, Actor|Closure $actor): void
    {
        $this->frames[] = ['scope' => $scope, 'actor' => $actor];
    }

    /** Lifecycle events may finish after the queue worker has reset scoped bindings. */
    public function leave(string $scope): void
    {
        for ($index = count($this->frames) - 1; $index >= 0; $index--) {
            if ($this->frames[$index]['scope'] === $scope) {
                $this->frames = array_slice($this->frames, 0, $index);

                return;
            }
        }
    }
}
