<?php

use App\Domain\Platform\Validity\ValidityColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_parents', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('parent_id')->constrained('organizations')->restrictOnDelete();
            ValidityColumns::add($table, ['organization_id']);
            $table->index(['parent_id', 'valid_from']);
            $table->timestamps(6);
        });
        DB::unprepared("CREATE TRIGGER organization_parents_no_delete BEFORE DELETE ON organization_parents
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hierarchy history cannot be deleted'");
    }

    public function down(): void
    {
        if (Schema::hasTable('organization_parents') && DB::table('organization_parents')->exists()) {
            throw new RuntimeException('Retain organization hierarchy history when rolling back application code.');
        }
        Schema::dropIfExists('organization_parents');
    }
};
