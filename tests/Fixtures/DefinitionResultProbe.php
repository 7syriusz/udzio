<?php

namespace Tests\Fixtures;

use App\Domain\Platform\Concerns\RecordsDefinitionVersion;
use Illuminate\Database\Eloquent\Model;

/** Test result produced by a DefinitionProbe version (E1.6). */
class DefinitionResultProbe extends Model
{
    use RecordsDefinitionVersion;

    protected $table = 'definition_result_probes';

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public static function definitionType(): string
    {
        return 'definition_probe';
    }
}
