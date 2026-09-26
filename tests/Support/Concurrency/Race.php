<?php

namespace Tests\Support\Concurrency;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * Deterministic race between independent worker processes on MySQL.
 *
 * The test holds a "gate" row lock in its own connection (the lock the operation under test takes
 * first), starts the workers, waits until every worker is blocked on a lock (proof of real
 * contention, read from performance_schema.data_lock_waits; INNODB_TRX
 * does not list a primary-key point lookup that waits in the "statistics" stage on MySQL 8.4), releases the gate and collects results.
 * Test data must be committed before the race (use DatabaseTruncation, not RefreshDatabase).
 */
class Race
{
    /**
     * @param  array<int, array{0: class-string<Scenario>, 1: array<string, mixed>}>  $workers
     * @param  array<int, mixed>  $gateBindings
     * @return array{blocked: int, results: array<int, array<string, mixed>>}
     */
    public static function run(string $gateSql, array $gateBindings, array $workers, float $timeoutSeconds = 10): array
    {
        $gate = self::connect();
        $gate->beginTransaction();
        $gate->prepare($gateSql)->execute($gateBindings);

        $processes = [];
        foreach ($workers as [$scenario, $arguments]) {
            $process = proc_open(
                [PHP_BINARY, __DIR__.'/worker.php', $scenario, json_encode($arguments, JSON_THROW_ON_ERROR)],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path(),
            );
            if (! is_resource($process)) {
                throw new RuntimeException('Nie udało się uruchomić procesu roboczego.');
            }
            $processes[] = [$process, $pipes];
        }

        $blocked = 0;
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $blocked = (int) DB::scalar(
                'SELECT COUNT(DISTINCT REQUESTING_ENGINE_TRANSACTION_ID) FROM performance_schema.data_lock_waits w
                 JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID
                 WHERE l.OBJECT_SCHEMA = DATABASE()'
            );
            if ($blocked >= count($workers)) {
                break;
            }
            usleep(50_000);
        }
        $gate->commit();
        $gate = null;

        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $decoded = json_decode((string) $stdout, true);
            if (! is_array($decoded)) {
                throw new RuntimeException("Proces roboczy nie zwrócił JSON. stdout: {$stdout} stderr: {$stderr}");
            }
            $results[] = $decoded;
        }

        return ['blocked' => $blocked, 'results' => $results];
    }

    private static function connect(): PDO
    {
        $config = config('database.connections.'.config('database.default'));

        return new PDO(
            "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }
}
