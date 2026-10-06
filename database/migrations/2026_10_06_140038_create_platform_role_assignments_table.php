<?php

use App\Domain\Platform\Validity\ValidityColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform roles of an account (E3.8b), as a relation in time (E1.5). Separate from organization role
 * assignments: no organization role ever gives a platform permission and the other way round.
 * `role` names a definition from config/platform.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 64);
            ValidityColumns::add($table, ['user_id', 'role']);
            $table->timestamps(6);
        });
        DB::unprepared("CREATE TRIGGER platform_role_assignments_no_delete BEFORE DELETE ON platform_role_assignments
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Platform role history cannot be deleted'");
    }

    public function down(): void
    {
        if (Schema::hasTable('platform_role_assignments') && DB::table('platform_role_assignments')->exists()) {
            throw new RuntimeException('Retain platform role history when rolling back application code.');
        }
        Schema::dropIfExists('platform_role_assignments');
    }
};
