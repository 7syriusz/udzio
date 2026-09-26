<?php

use App\Domain\Platform\Validity\ValidityColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Test-only table for the validity period pattern (E1.5). Loaded only by the test suite. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('validity_probes', function (Blueprint $table): void {
            $table->id();
            $table->string('person_ref', 64);
            $table->string('context_ref', 64);
            $table->string('function', 64);
            ValidityColumns::add($table, ['person_ref', 'context_ref']);
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validity_probes');
    }
};
