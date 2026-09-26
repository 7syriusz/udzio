<?php

// Worker process for concurrency tests. Boots the application in a fresh PHP process with its own
// database connection, runs one Tests\...\Scenario and prints the result as JSON on stdout.
// Usage (from Race only): php worker.php <scenario-class> <json-arguments>

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;
use Tests\Support\Concurrency\Scenario;

if (! str_ends_with((string) getenv('DB_DATABASE'), '_test')) {
    fwrite(STDERR, "Odmowa: worker działa wyłącznie na bazie *_test.\n");
    exit(1);
}

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$class = $argv[1] ?? '';
if (! str_starts_with($class, 'Tests\\') || ! is_subclass_of($class, Scenario::class)) {
    fwrite(STDERR, "Odmowa: {$class} nie jest scenariuszem testowym.\n");
    exit(1);
}

try {
    $result = (new $class)->run(json_decode($argv[2] ?? '{}', true, flags: JSON_THROW_ON_ERROR));
    echo json_encode(['ok' => true, 'result' => $result]);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e::class,
        'message' => $e->getMessage(),
        'errors' => $e instanceof ValidationException ? $e->errors() : null,
    ]);
}
