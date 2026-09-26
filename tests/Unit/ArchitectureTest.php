<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Conventions from docs/ARCHITEKTURA.md §4 that can be checked mechanically.
 */
class ArchitectureTest extends TestCase
{
    /** Modules allowed under app/Domain (docs/ARCHITEKTURA.md §1). */
    public const MODULES = [
        'Platform', 'Identity', 'Organization', 'Event', 'Participation',
        'Resource', 'Finance', 'Form', 'Communication', 'Reporting',
    ];

    public function test_env_is_read_only_in_config(): void
    {
        $offenders = $this->filesMatching(['app', 'routes', 'database', 'resources/views'], '/(?<![\w>:$])env\s*\(/');

        $this->assertSame([], $offenders, 'env() poza config/: '.implode(', ', $offenders));
    }

    public function test_no_debug_calls_in_application_code(): void
    {
        $offenders = $this->filesMatching(['app'], '/(?<![\w>:$])(dd|dump|var_dump|ray)\s*\(/');

        $this->assertSame([], $offenders, 'Kod diagnostyczny w app/: '.implode(', ', $offenders));
    }

    public function test_migrations_do_not_use_floating_point_or_database_enums(): void
    {
        $offenders = $this->filesMatching(['database/migrations'], '/->(float|double|enum)\s*\(/');

        $this->assertSame([], $offenders, 'float/double/enum w migracjach: '.implode(', ', $offenders));
    }

    public function test_domain_code_lives_only_in_known_modules(): void
    {
        $domain = $this->root('app/Domain');
        $unknown = is_dir($domain)
            ? array_values(array_diff(array_map('basename', glob($domain.'/*', GLOB_ONLYDIR)), self::MODULES))
            : [];

        $this->assertSame([], $unknown, 'Nieznane moduły w app/Domain: '.implode(', ', $unknown));
    }

    /**
     * @param  array<int, string>  $directories
     * @return array<int, string>
     */
    private function filesMatching(array $directories, string $pattern): array
    {
        $matches = [];
        foreach ($directories as $directory) {
            $path = $this->root($directory);
            if (! is_dir($path)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php' && preg_match($pattern, (string) file_get_contents($file->getPathname()))) {
                    $matches[] = substr($file->getPathname(), strlen($this->root('')));
                }
            }
        }
        sort($matches);

        return $matches;
    }

    private function root(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }
}
