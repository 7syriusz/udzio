<?php

use App\Domain\Platform\Validity\ValidityColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assignment of an ACCESS ROLE to an ACCOUNT in a SCOPE (A5 §2.4), as a relation in time (E1.5).
 * `scope_inheritance` is the explicit policy: the scope unit only, or the unit and its descendants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('access_role_id')->constrained('access_roles')->restrictOnDelete();
            $table->foreignId('scope_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('scope_inheritance', 32);
            ValidityColumns::add($table, ['user_id', 'access_role_id', 'scope_organization_id']);
            $table->index(['scope_organization_id', 'valid_from']);
            $table->timestamps(6);
        });
        DB::unprepared("CREATE TRIGGER role_assignments_no_delete BEFORE DELETE ON role_assignments
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Role assignment history cannot be deleted'");
    }

    public function down(): void
    {
        if (Schema::hasTable('role_assignments') && DB::table('role_assignments')->exists()) {
            throw new RuntimeException('Retain role assignment history when rolling back application code.');
        }
        Schema::dropIfExists('role_assignments');
    }
};
