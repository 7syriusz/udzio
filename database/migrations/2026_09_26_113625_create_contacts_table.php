<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** CONTACT (A5 §1.3): a communication channel owned by a PERSON — never an identity by itself. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->string('channel', 16);
            $table->string('value', 254);
            $table->dateTime('verified_at', 6)->nullable();
            $table->dateTime('removed_at', 6)->nullable();
            $table->string('active_key', 300)->nullable()
                ->storedAs("CASE WHEN removed_at IS NULL THEN CONCAT_WS('|', person_id, channel, value) END")
                ->unique();
            $table->timestamps(6);
            $table->index(['channel', 'value']);
        });

        Schema::create('contact_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at', 6);
            $table->dateTime('consumed_at', 6)->nullable();
            $table->dateTime('created_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_verifications');
        Schema::dropIfExists('contacts');
    }
};
