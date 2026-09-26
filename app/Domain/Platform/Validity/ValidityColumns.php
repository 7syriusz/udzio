<?php

namespace App\Domain\Platform\Validity;

use Illuminate\Database\Schema\Blueprint;
use InvalidArgumentException;

/**
 * Schema helper for tables that use HasValidityPeriod (E1.5). Adds the period columns and a
 * database guarantee that a relation has at most one open period: `open_key` is the relation key
 * while `valid_to` is NULL and NULL otherwise, with a unique index (NULLs never collide).
 */
final class ValidityColumns
{
    /** @param list<string> $keyColumns Columns that identify one relation (e.g. person_id, organization_id, role). */
    public static function add(Blueprint $table, array $keyColumns): void
    {
        if ($keyColumns === [] || array_filter($keyColumns, fn ($c) => ! preg_match('/^[a-z][a-z0-9_]*$/', $c)) !== []) {
            throw new InvalidArgumentException('Validity key requires plain column names.');
        }

        $table->string('status', 32);
        $table->dateTime('valid_from', 6);
        $table->dateTime('valid_to', 6)->nullable();
        $key = 'CONCAT_WS(\'|\', '.implode(', ', array_map(fn ($c) => "`{$c}`", $keyColumns)).')';
        $table->string('open_key', 512)->nullable()->storedAs("CASE WHEN `valid_to` IS NULL THEN {$key} END");
        // Explicit short names: generated names from long key columns exceed MySQL's 64-character limit.
        // The guard in HasValidityPeriod recognizes the conflict by the `open_key` fragment of the name.
        $table->unique('open_key', substr($table->getTable(), 0, 40).'_open_key_unique');
        $table->index([...$keyColumns, 'valid_from'], substr($table->getTable(), 0, 40).'_validity_index');
    }
}
