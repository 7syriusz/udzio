<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\ActorContext;
use App\Domain\Platform\Exceptions\IdempotencyConflict;
use App\Domain\Platform\Idempotency\IdempotentOutcome;
use App\Domain\Platform\Versioning\ContentHash;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Runs an operation at most once per (scope, actor, client key). The key row is inserted before the
 * operation in the same transaction, so a failed operation releases the key and a concurrent duplicate
 * waits on the unique index, then receives the stored result. The same key with different content is
 * refused (IdempotencyConflict).
 */
final class RunIdempotently
{
    public function __construct(private readonly ActorContext $context) {}

    /**
     * @param  string  $scope  operation name, e.g. 'registration.create'
     * @param  string  $key  client-supplied key (e.g. Idempotency-Key header), unique per intended operation
     * @param  array<string, mixed>  $payload  the request content that defines "the same request"
     * @param  Closure(): mixed  $operation  returns a JSON-compatible result
     */
    public function handle(string $scope, string $key, array $payload, Closure $operation): IdempotentOutcome
    {
        if (! preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $scope)) {
            throw new InvalidArgumentException('Invalid idempotency scope.');
        }
        if (! preg_match('/^[\x21-\x7E]{8,191}$/', $key)) {
            throw new InvalidArgumentException('Idempotency key must be 8-191 printable ASCII characters.');
        }
        $actor = $this->context->current();
        $owner = $actor->type->value.':'.($actor->identifier ?? '');
        $hash = ContentHash::of($payload);
        $identity = ['scope' => $scope, 'owner' => $owner, 'idempotency_key' => $key];

        return DB::transaction(function () use ($identity, $hash, $operation): IdempotentOutcome {
            try {
                $id = DB::table('idempotency_keys')->insertGetId([...$identity, 'request_hash' => $hash, 'created_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) !== 1062) {
                    throw $e;
                }

                return $this->replay($identity, $hash);
            }

            $value = $operation();
            DB::table('idempotency_keys')->where('id', $id)->update(['response' => json_encode($value, JSON_THROW_ON_ERROR)]);

            return new IdempotentOutcome($value, false);
        }, attempts: 3); // waiters on a rolled-back key insert deadlock each other; the retry replays or runs once
    }

    /** @param array<string, string> $identity */
    private function replay(array $identity, string $hash): IdempotentOutcome
    {
        $stored = DB::table('idempotency_keys')->where($identity)->lockForUpdate()->first();
        if ($stored->request_hash !== $hash) {
            throw new IdempotencyConflict('This idempotency key was already used for a different request.');
        }

        return new IdempotentOutcome(json_decode((string) $stored->response, true, flags: JSON_THROW_ON_ERROR), true);
    }
}
