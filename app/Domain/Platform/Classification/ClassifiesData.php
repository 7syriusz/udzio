<?php

namespace App\Domain\Platform\Classification;

use App\Domain\Platform\Enums\DataClass;

/** A model whose fields are classified (A5 §12). Unclassified fields cannot be written by audited models. */
interface ClassifiesData
{
    /** @return array<string, DataClass> field => class */
    public function dataClassification(): array;
}
