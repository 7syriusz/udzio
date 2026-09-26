<?php

use App\Domain\Platform\Validity\ValidityColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Representation (A5 §1.4): one PERSON acts for another within explicit scopes, for a period. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('representations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('representative_person_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('represented_person_id')->constrained('people')->restrictOnDelete();
            $table->string('kind', 32);
            $table->json('scopes');
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
