<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security policy of a role (E3.8): the role explicitly requires MFA, independently of its name. A role holding
 * privileged permissions requires MFA anyway (PrivilegedAccessPolicy). Part of the versioned role definition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_roles', function (Blueprint $table): void {
            $table->boolean('requires_mfa')->default(false)->after('grant_rules');
        });
    }

    public function down(): void
    {
        Schema::table('access_roles', function (Blueprint $table): void {
            $table->dropColumn('requires_mfa');
        });
    }
};
