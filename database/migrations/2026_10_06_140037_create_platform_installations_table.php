<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one-time installation of the platform (E3.8b): at most one row, always id 1, written in the same
 * transaction as the first platform administrator. The primary key makes a second installation — also a
 * concurrent one — impossible; the row is never deleted. Empty after `migrate` (no seeded data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_installations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->dateTime('installed_at', 6);
            $table->foreignId('administrator_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('installed_by', 191);
            $table->timestamps(6);
        });
        DB::unprepared("CREATE TRIGGER platform_installations_single_row BEFORE INSERT ON platform_installations
            FOR EACH ROW BEGIN IF NEW.id <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Platform is installed once'; END IF; END");
        DB::unprepared("CREATE TRIGGER platform_installations_no_delete BEFORE DELETE ON platform_installations
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Platform installation cannot be deleted'");
    }

    public function down(): void
    {
        if (Schema::hasTable('platform_installations') && DB::table('platform_installations')->exists()) {
            throw new RuntimeException('Retain the platform installation record when rolling back application code.');
        }
        Schema::dropIfExists('platform_installations');
    }
};
