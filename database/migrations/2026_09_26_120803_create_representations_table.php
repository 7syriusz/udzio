<?php

use App\Domain\Platform\Validity\ValidityColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REPRESENTATION (A5 §1.4, Z-025): one PERSON acts for another within explicit scopes, for a period.
 * Universal — "parent and child" is one use of it, not a type. Every period records how it was
 * established (method), on what basis, and by which ACTOR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('representations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('representative_person_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('represented_person_id')->constrained('people')->restrictOnDelete();
            $table->json('scopes');
            $table->string('method', 32);
            $table->string('basis', 500);
            $table->string('established_by_type', 32);
            $table->string('established_by_id', 191)->nullable();
            ValidityColumns::add($table, ['representative_person_id', 'represented_person_id']);
            $table->timestamps(6);
            $table->index(['represented_person_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('representations');
    }
};
