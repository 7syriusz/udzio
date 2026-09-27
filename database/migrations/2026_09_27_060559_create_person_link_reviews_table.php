<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Controlled repair procedure for an ACCOUNT whose verified e-mail matches several PERSONs (A5-02, Z-022).
 * Nothing is merged automatically; an authorized role resolves the case. One open case per account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_link_reviews', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 32);
            $table->json('candidate_person_ids');
            $table->foreignId('resolved_person_id')->nullable()->constrained('people')->restrictOnDelete();
            $table->dateTime('resolved_at', 6)->nullable();
            $table->unsignedBigInteger('open_key')->nullable()
                ->storedAs("CASE WHEN status = 'open' THEN user_id END")
                ->unique();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_link_reviews');
    }
};
