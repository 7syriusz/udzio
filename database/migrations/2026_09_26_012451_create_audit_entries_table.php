<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('actor_type', 32);
            $table->string('actor_id', 191)->nullable();
            $table->string('subject_type', 100);
            $table->string('subject_id', 191);
            $table->string('organization_id', 191)->nullable();
            $table->string('action', 100);
            $table->string('result', 32);
            $table->text('reason')->nullable();
            $table->ulid('correlation_id')->index();
            $table->dateTime('occurred_at', 6);
            $table->index(['organization_id', 'id']);
            $table->index(['subject_type', 'subject_id', 'id']);
        });

        DB::unprepared("CREATE TRIGGER audit_entries_no_update BEFORE UPDATE ON audit_entries
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit entries are append-only'");
        DB::unprepared("CREATE TRIGGER audit_entries_no_delete BEFORE DELETE ON audit_entries
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit entries are append-only'");
    }

    public function down(): void
    {
        if (Schema::hasTable('audit_entries') && DB::table('audit_entries')->exists()) {
            throw new RuntimeException('Cannot drop audit history. Roll back application code and retain the audit table.');
        }

        Schema::dropIfExists('audit_entries');
    }
};
