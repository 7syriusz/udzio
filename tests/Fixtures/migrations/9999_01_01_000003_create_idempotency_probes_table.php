<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Test-only table: side effects of idempotent operations (E1.8). Loaded only by the test suite. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_probes', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 100);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_probes');
    }
};
