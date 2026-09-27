<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role-granting catalog of a managing role (E3.6a): which roles its holders may assign and revoke, where,
 * for how long and whether with approval. Part of the versioned role definition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_roles', function (Blueprint $table): void {
            $table->json('grant_rules')->nullable()->after('permissions');
        });
        Schema::table('role_assignments', function (Blueprint $table): void {
            $table->dateTime('requested_until', 6)->nullable()->after('scope_inheritance');
            $table->string('requested_by_type', 32)->nullable()->after('requested_until');
            $table->string('requested_by_id', 191)->nullable()->after('requested_by_type');
        });
    }

    public function down(): void
    {
        Schema::table('role_assignments', function (Blueprint $table): void {
            $table->dropColumn(['requested_until', 'requested_by_type', 'requested_by_id']);
        });
        Schema::table('access_roles', function (Blueprint $table): void {
            $table->dropColumn('grant_rules');
        });
    }
};
