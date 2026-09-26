<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Test-only tables for the definition → version → result pattern (E1.6). Loaded only by the test suite. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('definition_probes', function (Blueprint $table): void {
            $table->id();
            $table->string('organization_ref', 64);
            $table->string('name', 100);
            $table->json('rules');
            $table->timestamps(6);
        });

        Schema::create('definition_result_probes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('definition_version_id')->constrained('definition_versions')->restrictOnDelete();
            $table->integer('score');
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('definition_result_probes');
        Schema::dropIfExists('definition_probes');
    }
};
