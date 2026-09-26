<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACCOUNT (A5 §1.2): the users table is the access layer. Names typed at registration stay on the
 * account until it is linked to a PERSON (E2.4); one PERSON has at most one account (unique person_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('name');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('person_id')->nullable()->unique()->after('id')->constrained('people')->restrictOnDelete();
            $table->string('given_name', 100)->after('person_id');
            $table->string('family_name', 100)->after('given_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('person_id');
            $table->dropColumn(['given_name', 'family_name']);
            $table->string('name')->after('id')->default('');
        });
    }
};
