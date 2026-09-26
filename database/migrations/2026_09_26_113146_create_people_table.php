<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PERSON (A5 §1.1): a real person, global and independent of organization, event and scenario. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('given_name', 100);
            $table->string('family_name', 100);
            $table->date('birth_date')->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
