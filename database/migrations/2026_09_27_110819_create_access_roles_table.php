<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ACCESS ROLE (A5 §2.4): a named set of PERMISSIONs defined by one organization. Configuration, not code;
 * roles are retired, never deleted. Assignment to accounts and SCOPE follow in E3.5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_roles', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('name', 100);
            $table->json('permissions');
            $table->string('status', 32);
            $table->string('active_name_key', 200)->nullable()
                ->storedAs("CASE WHEN status = 'active' THEN CONCAT(organization_id, '|', name) END")
                ->unique();
            $table->timestamps(6);
        });
        DB::unprepared("CREATE TRIGGER access_roles_no_delete BEFORE DELETE ON access_roles
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Access roles are retired, not deleted'");
    }

    public function down(): void
    {
        if (Schema::hasTable('access_roles') && DB::table('access_roles')->exists()) {
            throw new RuntimeException('Retain access role history when rolling back application code.');
        }
        Schema::dropIfExists('access_roles');
    }
};
