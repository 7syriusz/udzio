<?php

// Strażnik bazy testowej (pierwsza linia obrony, przed startem aplikacji). Testy migrują i czyszczą
// bazę, więc mogą działać wyłącznie na MySQL i na bazie *_test. Druga linia obrony,
// sprawdzająca faktyczne połączenie Laravela: Tests\TestCase::createApplication().

require __DIR__.'/../vendor/autoload.php';

foreach ([getenv('DB_DATABASE'), $_SERVER['DB_DATABASE'] ?? null, $_ENV['DB_DATABASE'] ?? null] as $database) {
    if ($database !== null && $database !== false && ! str_ends_with((string) $database, '_test')) {
        fwrite(STDERR, "Odmowa uruchomienia testów: baza {$database} nie jest bazą testową (*_test).\n");
        exit(1);
    }
}

if (getenv('DB_CONNECTION') !== 'mysql') {
    fwrite(STDERR, "Odmowa uruchomienia testów: wymagany MySQL (DB_CONNECTION=".getenv('DB_CONNECTION').").\n");
    exit(1);
}
