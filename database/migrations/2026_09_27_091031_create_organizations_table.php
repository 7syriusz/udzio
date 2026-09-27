<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name');
            $table->string('status', 32)->default('active');
            $table->timestamps(6);
        });

        DB::unprepared("CREATE TRIGGER organizations_no_delete BEFORE DELETE ON organizations
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Organizations must be archived'");
    }

    public function down(): void
    {
        if (Schema::hasTable('organizations') && DB::table('organizations')->exists()) {
            throw new RuntimeException('Cannot drop organizations with history. Roll back application code and retain the table.');
        }

        Schema::dropIfExists('organizations');
    }
};
