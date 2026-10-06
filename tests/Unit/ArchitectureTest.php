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

    public function test_language_is_chosen_only_by_the_locale_middleware(): void
    {
        $offenders = array_diff($this->filesMatching(['app', 'routes', 'resources/views'], '/setLocale\s*\(/'), ['app/Http/Middleware/SetLocale.php']);

        $this->assertSame([], array_values($offenders), 'Język ustawiany poza SetLocale (E3.6b): '.implode(', ', $offenders));
    }

    public function test_user_messages_in_code_are_translation_keys(): void
    {
        $patterns = [
            'komunikat walidacji' => '/withMessages\(\[\s*[\'"][^\'"]*[\'"]\s*=>\s*[\'"]/',
            'komunikat po zapisie' => '/->with\(\s*[\'"]status[\'"]\s*,\s*[\'"]/',
            'komunikat błędu HTTP' => '/abort\(\s*\d{3}\s*,\s*[\'"](?![a-z_]+(\.[a-z0-9_]+)+[\'"])/',
            'tytuł wiadomości' => '/->(subject|line|greeting|action)\(\s*[\'"]/',
        ];
        $offenders = [];
        foreach ($patterns as $kind => $pattern) {
            foreach ($this->filesMatching(['app'], $pattern) as $file) {
                $offenders[] = "{$kind}: {$file}";
            }
        }

        $this->assertSame([], $offenders, 'Teksty dla użytkownika wpisane w kodzie zamiast kluczy tłumaczeń (E3.6b).');
    }

    /**
     * Until their screens add re-authentication and central control (E3.10/E3.11, Z-040), these operations are
     * reachable from the console only: no controller, route, API or form may call them. The Identity procedures
     * of E2 (`ResolvePersonLinkReview`, `GrantRepresentation`) are reached only through the authorized
     * Organization actions (E3.9, Z-042).
     */
    public function test_privileged_operations_have_no_http_entry_yet(): void
    {
        $offenders = $this->filesMatching(['app/Http', 'routes'], '/\b(ResetAccountMfa|EmergencyResetAccountMfa|GrantPlatformRole|InstallFirstAdministrator|CreateOrganization|ResolvePersonLinkReview|GrantRepresentation)\b/');

        $this->assertSame([], $offenders, 'Operacja uprzywilejowana dostępna przez HTTP przed wdrożeniem ekranu z kontrolą: '.implode(', ', $offenders));
    }

    public function test_views_contain_no_hardcoded_text(): void
    {
        $offenders = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root('resources/views'), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $view = (string) file_get_contents($file->getPathname());
            $view = preg_replace(['/\{\{--.*?--\}\}/s', '/\{\{.*?\}\}/s', '/\{!!.*?!!\}/s'], ' ', $view);
            $view = preg_replace('/__\(\s*\'[^\']*\'/', '__(', $view);
            // Quoted text inside Blade directives (e.g. labels passed to @include) must be keys too.
            $directives = [];
            preg_match_all('/@\w+\s*(\((?:[^()\'"]|\'[^\']*\'|"[^"]*"|(?1))*\))/', $view, $directives);
            foreach ($directives[1] as $arguments) {
                preg_match_all('/\'[^\']*\'/', $arguments, $literals);
                foreach ($literals[0] as $literal) {
                    if (preg_match('/\s\p{L}|[^\x00-\x7F]/u', $literal)) {
                        $offenders[] = $file->getFilename().': '.$literal;
                    }
                }
            }
            $view = preg_replace('/@\w+\s*(\((?:[^()\'"]|\'[^\']*\'|"[^"]*"|(?1))*\))?/', ' ', $view);
            if (preg_match('/\s(aria-label|placeholder|title|alt)="[^"]*\p{L}/u', $view, $attribute)) {
                $offenders[] = $file->getFilename().': '.$attribute[0];
            }
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($view)));
            if (preg_match('/\p{L}{2,}/u', $text)) {
                $offenders[] = $file->getFilename().': '.mb_substr($text, 0, 80);
            }
        }

        $this->assertSame([], $offenders, 'Tekst w widokach poza kluczami tłumaczeń (E3.6b).');
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
